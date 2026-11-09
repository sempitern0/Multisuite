<?php

namespace App\Console\Commands;

use App\Models\ServiceAccount;
use App\Services\ServiceAccounts;
use Illuminate\Console\Command;

class SyncServiceAccounts extends Command
{
    protected $signature = 'portal:sync-accounts';

    protected $description = 'Retry service provisioning and remove expired temporary credentials';

    public function handle(ServiceAccounts $accounts): int
    {
        ServiceAccount::whereNotNull('pending_password')
            ->where(function ($query) {
                $query->where('retry_at', '<=', now())->orWhere('credential_expires_at', '<=', now());
            })->chunkById(50, function ($rows) use ($accounts): void {
                foreach ($rows as $row) {
                    $accounts->synchronize($row->id);
                }
            });

        return self::SUCCESS;
    }
}
