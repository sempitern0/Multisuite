<?php

namespace App\Services\Provisioning;

use App\Models\ServiceAccount;

/**
 * Port to a third-party service that keeps its own accounts. Every operation is idempotent
 * (safe to repeat after a lost response) and reports failures only as RuntimeException with
 * the message `unavailable`, `conflict` or `configuration`: never remote payloads or secrets.
 */
interface ServiceProvisioner
{
    /** Creates the remote account or replaces its password, keeping its remote settings. */
    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void;

    /** Changes the sign-in name without touching the password. A missing account is not an error. */
    public function rename(ServiceAccount $account, string $username): void;

    /** Blocks sign-in while keeping the account and its data (password replaced by a discarded random one). */
    public function lock(ServiceAccount $account): void;

    /** Deletes the account and confirms it is gone. */
    public function remove(ServiceAccount $account): void;
}
