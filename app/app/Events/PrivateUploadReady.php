<?php

namespace App\Events;

/** Dispatched inside the receive transaction once a private upload is verified and committed. */
final class PrivateUploadReady
{
    public function __construct(public readonly string $uploadId, public readonly int $userId) {}
}
