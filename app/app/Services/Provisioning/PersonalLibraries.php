<?php

namespace App\Services\Provisioning;

use App\Models\ServiceAccount;

/**
 * A service that can show one person a private library read from the directory MediaLibrary
 * fills ({service mount}/personal/{account uuid}[/{section}] inside its container). Idempotent:
 * creates the library if missing, gives access to that account only, and asks for a scan.
 * Failures are RuntimeException('unavailable'|'conflict'|'configuration') like ServiceProvisioner.
 */
interface PersonalLibraries
{
    /** Path prefix, inside the service container, under which personal libraries live. */
    public const MOUNT = '/personal';

    public function ensurePersonalLibrary(ServiceAccount $account, ?string $section, string $label): void;
}
