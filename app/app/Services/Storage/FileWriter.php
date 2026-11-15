<?php

namespace App\Services\Storage;

/** Sequential writer of a private file: encrypted (EncryptedWriter) or plain (PlainWriter). */
interface FileWriter
{
    public function write(string $data): void;

    /** Flushes, fsyncs and closes; the file is complete afterwards. */
    public function finish(): void;

    /** Closes without completing (the caller deletes the partial file). */
    public function abort(): void;
}
