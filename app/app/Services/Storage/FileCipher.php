<?php

namespace App\Services\Storage;

use RuntimeException;

/**
 * Encryption at rest for private files and ZIP exports (format "msf1").
 *
 * Layout: header (magic "MSF1", chunk size, 16-byte random nonce prefix) followed by
 * 64 KiB chunks, each sealed with XChaCha20-Poly1305 (libsodium). The nonce is the
 * prefix plus the chunk index, and every chunk authenticates the header, its index and
 * whether it is the last one, so reordering, swapping or truncating chunks is detected.
 * Chunks make byte ranges cheap to serve (video seeking) without decrypting the rest.
 *
 * Each file gets its own key derived (HKDF-SHA256) from the operator's master key
 * (NAS_FILES_KEY, never stored in the database or on the data disk) and a context such
 * as "file:<upload id>". Losing the master key makes every encrypted file unreadable.
 */
final class FileCipher
{
    public const FORMAT = 'msf1';

    private const MAGIC = 'MSF1';

    /** Plaintext bytes per sealed chunk; streamed appends must be multiples of it until the last one. */
    public const CHUNK = 65536;

    private const TAG = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    private const HEADER = 24; // magic (4) + chunk size (4) + nonce prefix (16)

    public function __construct(#[\SensitiveParameter] private readonly string $masterKey)
    {
        if (strlen($masterKey) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('La clave de cifrado de archivos debe tener 32 bytes.');
        }
    }

    /** The configured cipher, or null when NAS_FILES_KEY is not set. */
    public static function fromConfig(): ?self
    {
        $key = (string) config('nas.files_key', '');
        if ($key === '') {
            return null;
        }
        $raw = base64_decode(str_starts_with($key, 'base64:') ? substr($key, 7) : $key, true);
        if ($raw === false) {
            throw new RuntimeException('NAS_FILES_KEY no es base64 válido.');
        }

        return new self($raw);
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    /** Encrypts a whole plaintext file into $destination (created exclusively, mode 0600, fsynced). */
    public function encryptFile(string $source, string $destination, string $context): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('No se puede leer el archivo para cifrarlo.');
        }
        try {
            $writer = $this->writer($destination, $context);
            while (! feof($input)) {
                $data = fread($input, self::CHUNK);
                if ($data === false) {
                    throw new RuntimeException('Lectura interrumpida al cifrar.');
                }
                $writer->write($data);
            }
            $writer->finish();
        } finally {
            fclose($input);
        }
    }

    /** Streaming writer for content whose size is not known in advance (ZIP exports). */
    public function writer(string $destination, string $context): EncryptedWriter
    {
        $handle = @fopen($destination, 'xb');
        if ($handle === false) {
            throw new RuntimeException('No se puede crear el archivo cifrado.');
        }
        if (! chmod($destination, 0600)) {
            fclose($handle);
            throw new RuntimeException('No se pueden restringir los permisos del archivo cifrado.');
        }
        $header = self::MAGIC.pack('N', self::CHUNK).random_bytes(16);

        $key = $this->key($context);

        return new EncryptedWriter($handle, $header, self::CHUNK, fn (string $plain, int $index, bool $final): string => $this->seal($plain, $header, $index, $final, $key));
    }

    /**
     * Appends plaintext to an encrypted file that is still being written across several
     * requests (chunked uploads). Non-final appends must be whole chunks; $final seals the
     * last one. A half-written chunk left by a crash is dropped first, so the file always
     * holds a valid prefix and appendedBytes() says where to resume.
     */
    public function append(string $path, string $context, string $data, bool $final): void
    {
        if (! $final && ($data === '' || strlen($data) % self::CHUNK !== 0)) {
            throw new RuntimeException('Los fragmentos intermedios deben ser múltiplos de 64 KiB.');
        }
        if (! file_exists($path)) {
            $created = @fopen($path, 'xb');
            if ($created === false || ! chmod($path, 0600) || fwrite($created, self::MAGIC.pack('N', self::CHUNK).random_bytes(16)) !== self::HEADER || ! fclose($created)) {
                throw new RuntimeException('No se puede crear el archivo cifrado.');
            }
        }
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('No se puede abrir el archivo cifrado.');
        }
        try {
            $header = $this->header($handle);
            $index = intdiv($this->appendedBytes($path), self::CHUNK);
            $length = max(0, self::HEADER + $index * (self::CHUNK + self::TAG));
            if (! ftruncate($handle, $length) || fseek($handle, $length) !== 0) {
                throw new RuntimeException('No se puede preparar el archivo cifrado.');
            }
            $key = $this->key($context);
            $pieces = $data === '' ? [''] : str_split($data, self::CHUNK);
            foreach ($pieces as $position => $piece) {
                $sealed = $this->seal($piece, $header, $index++, $final && $position === count($pieces) - 1, $key);
                if (fwrite($handle, $sealed) !== strlen($sealed)) {
                    throw new RuntimeException('Escritura incompleta del archivo cifrado; revisa el espacio disponible.');
                }
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('No se ha podido guardar el archivo cifrado.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Plaintext bytes already appended to an unfinished encrypted file (whole chunks only). */
    public function appendedBytes(string $path): int
    {
        clearstatcache(true, $path);
        $length = filesize($path);
        if ($length === false || $length < self::HEADER) {
            throw new RuntimeException('El archivo cifrado está incompleto.');
        }

        return intdiv($length - self::HEADER, self::CHUNK + self::TAG) * self::CHUNK;
    }

    /** Plaintext size of an encrypted file, from its length alone. */
    public function plainSize(string $path): int
    {
        $body = $this->body($path);

        return $body - $this->chunkCount($body) * self::TAG;
    }

    /**
     * Decrypts plaintext bytes [$offset, $offset + $length) and passes them to $sink in
     * pieces. Throws (and stops) at the first chunk that fails authentication.
     *
     * @param  callable(string): void  $sink
     */
    public function read(string $path, string $context, int $offset, int $length, callable $sink): void
    {
        $body = $this->body($path);
        $count = $this->chunkCount($body);
        $size = $body - $count * self::TAG;
        if ($offset < 0 || $length < 0 || $offset + $length > $size) {
            throw new RuntimeException('Rango fuera del archivo.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se puede abrir el archivo cifrado.');
        }
        try {
            $header = $this->header($handle);
            $key = $this->key($context);
            $end = $offset + $length;
            for ($index = intdiv($offset, self::CHUNK); $index < $count && $index * self::CHUNK < max($end, 1); $index++) {
                if (fseek($handle, self::HEADER + $index * (self::CHUNK + self::TAG)) !== 0) {
                    throw new RuntimeException('No se puede leer el archivo cifrado.');
                }
                $sealed = (string) fread($handle, self::CHUNK + self::TAG);
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed, $this->additional($header, $index, $index === $count - 1), $this->nonce($header, $index), $key);
                if ($plain === false) {
                    throw new RuntimeException('El archivo cifrado está dañado o la clave no es la correcta.');
                }
                $start = $index * self::CHUNK;
                $from = max(0, $offset - $start);
                $to = min(strlen($plain), $end - $start);
                if ($to > $from) {
                    $sink(substr($plain, $from, $to - $from));
                }
                sodium_memzero($plain);
            }
        } finally {
            fclose($handle);
        }
    }

    /** Opens a whole encrypted file as a plaintext string (small files only, e.g. previews). */
    public function readPrefix(string $path, string $context, int $maxBytes): string
    {
        $out = '';
        $this->read($path, $context, 0, min($maxBytes, $this->plainSize($path)), function (string $piece) use (&$out): void {
            $out .= $piece;
        });

        return $out;
    }

    /** @param  resource  $handle */
    private function header($handle): string
    {
        $header = (string) fread($handle, self::HEADER);
        $chunk = strlen($header) === self::HEADER ? unpack('N', substr($header, 4, 4)) : false;
        if ($chunk === false || ! str_starts_with($header, self::MAGIC) || ($chunk[1] ?? null) !== self::CHUNK) {
            throw new RuntimeException('El archivo no tiene el formato cifrado esperado.');
        }

        return $header;
    }

    private function seal(string $plain, string $header, int $index, bool $final, string $key): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $this->additional($header, $index, $final), $this->nonce($header, $index), $key);
    }

    private function key(string $context): string
    {
        return hash_hkdf('sha256', $this->masterKey, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'multisuite:'.self::FORMAT.':'.$context);
    }

    private function nonce(string $header, int $index): string
    {
        return substr($header, 8, 16).pack('J', $index);
    }

    private function additional(string $header, int $index, bool $final): string
    {
        return $header.pack('J', $index).($final ? "\x01" : "\x00");
    }

    /** Chunks in a body of sealed bytes (an empty file still has one final chunk). */
    private function chunkCount(int $body): int
    {
        return max(1, intdiv($body + self::CHUNK + self::TAG - 1, self::CHUNK + self::TAG));
    }

    /** Sealed bytes after the header. */
    private function body(string $path): int
    {
        clearstatcache(true, $path);
        $length = filesize($path);
        $body = $length === false ? -1 : $length - self::HEADER;
        if ($body < self::TAG) {
            throw new RuntimeException('El archivo cifrado está incompleto.');
        }

        return $body;
    }
}
