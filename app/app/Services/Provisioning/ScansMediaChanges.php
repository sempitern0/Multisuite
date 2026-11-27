<?php

namespace App\Services\Provisioning;

interface ScansMediaChanges
{
    /** Ask the service to discover new files and remove missing files from its catalogue. */
    public function scanMedia(): void;
}
