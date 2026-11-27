<?php

namespace App\Console\Commands;

use App\Models\ServiceAccount;
use App\Services\KomgaAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Console\Command;

class SyncServiceAccounts extends Command
{
    protected $signature = 'portal:sync-accounts';

    protected $description = 'Retry service provisioning and remove expired temporary credentials';

    public function handle(ServiceAccounts $accounts): int
    {
        app(KomgaAccounts::class)->reserveExisting();
        ServiceAccount::where(fn ($query) => $query->whereNotNull('pending_password')->orWhere('status', ServiceAccounts::LOCK_PENDING))
            ->where(function ($query) {
                $query->where('retry_at', '<=', now())->orWhere('credential_expires_at', '<=', now())
                    // Blocks waiting for a service to be configured again (no remote call until then).
                    ->orWhere(fn ($waiting) => $waiting->where('status', ServiceAccounts::LOCK_PENDING)->whereNull('retry_at'));
            })->chunkById(50, function ($rows) use ($accounts): void {
                foreach ($rows as $row) {
                    $accounts->synchronize($row->id);
                }
            });

        return self::SUCCESS;
    }
}
