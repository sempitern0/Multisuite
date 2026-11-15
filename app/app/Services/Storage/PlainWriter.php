<?php

namespace App\Services\Storage;

use RuntimeException;

/** Unencrypted FileWriter, used only when NAS_ENCRYPT_FILES is off. */
final class PlainWriter implements FileWriter
{
    private bool $open = true;

    /** @param  resource  $handle */
    private function __construct(private $handle) {}

    public static function create(string $path): self
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('No se puede crear el archivo.');
        }
        if (! chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('No se pueden restringir los permisos del archivo.');
        }

        return new self($handle);
    }

    public function write(string $data): void
    {
        if (fwrite($this->handle, $data) !== strlen($data)) {
            throw new RuntimeException('Escritura incompleta; revisa el espacio disponible.');
        }
    }

    public function finish(): void
    {
        $this->open = false;
        $ok = fflush($this->handle) && fsync($this->handle);
        fclose($this->handle);
        if (! $ok) {
            throw new RuntimeException('No se ha podido guardar el archivo.');
        }
    }

    public function abort(): void
    {
        if ($this->open) {
            $this->open = false;
            fclose($this->handle);
        }
    }
}
