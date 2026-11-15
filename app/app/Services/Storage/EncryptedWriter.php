<?php

namespace App\Services\Storage;

use Closure;
use RuntimeException;

/**
 * Buffers plaintext and writes it sealed in chunks (see FileCipher). The last chunk is
 * only known at finish(), so one full chunk is always held back until then.
 */
final class EncryptedWriter
{
    private string $buffer = '';

    private int $index = 0;

    private bool $open = true;

    /**
     * @param  resource  $handle
     * @param  Closure(string, int, bool): string  $seal
     */
    public function __construct(private $handle, string $header, private readonly int $chunk, private readonly Closure $seal)
    {
        $this->put($header);
    }

    public function write(string $data): void
    {
        if (! $this->open) {
            throw new RuntimeException('El archivo cifrado ya está cerrado.');
        }
        $this->buffer .= $data;
        while (strlen($this->buffer) > $this->chunk) {
            $this->put(($this->seal)(substr($this->buffer, 0, $this->chunk), $this->index++, false));
            $this->buffer = substr($this->buffer, $this->chunk);
        }
    }

    public function finish(): void
    {
        $this->put(($this->seal)($this->buffer, $this->index++, true));
        $this->buffer = '';
        $this->open = false;
        if (! fflush($this->handle) || ! fsync($this->handle)) {
            fclose($this->handle);
            throw new RuntimeException('No se ha podido guardar el archivo cifrado.');
        }
        fclose($this->handle);
    }

    /** Closes without completing (the caller deletes the partial file). */
    public function abort(): void
    {
        if ($this->open) {
            $this->open = false;
            fclose($this->handle);
        }
    }

    private function put(string $bytes): void
    {
        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Escritura incompleta del archivo cifrado; revisa el espacio disponible.');
        }
    }
}
