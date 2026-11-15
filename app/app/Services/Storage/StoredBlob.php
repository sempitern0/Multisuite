<?php

namespace App\Services\Storage;

use RuntimeException;

/**
 * Plaintext view of a stored private file (or ZIP export), whether it is encrypted at
 * rest (FileCipher) or was stored before encryption existed. Callers never touch the
 * path for reading: they ask for a size, a byte range or a short prefix.
 */
final class StoredBlob
{
    public function __construct(public readonly string $path, private readonly ?string $cipher, private readonly string $context) {}

    public function encrypted(): bool
    {
        return $this->cipher !== null;
    }

    /** Opens the first chunk, so a missing or wrong key is reported before anything is sent. */
    public function check(): self
    {
        if ($this->cipher !== null && $this->size() > 0) {
            $this->stream(0, 1, fn (string $piece) => null);
        }

        return $this;
    }

    public function size(): int
    {
        if ($this->cipher !== null) {
            return $this->fileCipher()->plainSize($this->path);
        }
        clearstatcache(true, $this->path);

        return (int) filesize($this->path);
    }

    /** @param  callable(string): void  $sink */
    public function stream(int $offset, int $length, callable $sink): void
    {
        if ($this->cipher !== null) {
            $this->fileCipher()->read($this->path, $this->context, $offset, $length, $sink);

            return;
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            throw new RuntimeException('No se puede leer el archivo.');
        }
        try {
            while ($length > 0 && ! feof($handle)) {
                $piece = fread($handle, min(65536, $length));
                if ($piece === false || $piece === '') {
                    break;
                }
                $length -= strlen($piece);
                $sink($piece);
            }
        } finally {
            fclose($handle);
        }
    }

    public function prefix(int $maxBytes): string
    {
        $out = '';
        $this->stream(0, min($maxBytes, $this->size()), function (string $piece) use (&$out): void {
            $out .= $piece;
        });

        return $out;
    }

    private function fileCipher(): FileCipher
    {
        if ($this->cipher !== FileCipher::FORMAT) {
            throw new RuntimeException('Formato de cifrado desconocido.');
        }

        return FileCipher::fromConfig() ?? throw new RuntimeException('Falta la clave de cifrado de archivos (NAS_FILES_KEY): no se puede abrir el archivo.');
    }
}
