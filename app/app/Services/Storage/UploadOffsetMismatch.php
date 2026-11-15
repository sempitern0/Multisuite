<?php

namespace App\Services\Storage;

use RuntimeException;

/**
 * A chunk did not start where the stored part ends (lost response, retry, second tab).
 * Carries the bytes already received so the client resumes from there.
 */
class UploadOffsetMismatch extends RuntimeException
{
    public function __construct(public readonly int $received)
    {
        parent::__construct('El fragmento no continúa donde terminó el anterior.');
    }
}
