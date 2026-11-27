<?php

namespace App\Services\Provisioning;

use App\Models\ServiceAccount;

/**
 * A service that can show everyone the common library (content an administrator published, S04b),
 * read from {service mount}/personal/comun[/{section}]. Idempotent per account: creates the
 * library if missing and gives this account access to it. Failures like PersonalLibraries.
 */
interface CommonLibraries
{
    /** Directory name of the common library next to the personal ones (never an account uuid). */
    public const DIRECTORY = 'comun';

    public function ensureCommonLibrary(ServiceAccount $account, ?string $section, string $label): void;
}
