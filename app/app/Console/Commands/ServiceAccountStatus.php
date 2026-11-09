<?php

namespace App\Console\Commands;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Console\Command;

class ServiceAccountStatus extends Command
{
    protected $signature = 'portal:accounts-status {email}';

    protected $description = 'Show provisioning status without exposing passwords or tokens';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('Portal account not found.');

            return self::FAILURE;
        }
        $this->table(['Configuration', 'Value'], [
            ['Music enabled', config('portal.services.music.enabled') ? 'yes' : 'no'],
            ['Provisioning enabled', config('provisioning.music.enabled') ? 'yes' : 'no'],
            ['Internal host', parse_url((string) config('provisioning.music.url'), PHP_URL_HOST) ?: '(missing)'],
            ['Admin username configured', filled(config('provisioning.music.username')) ? 'yes' : 'no'],
            ['Admin password configured', filled(config('provisioning.music.password')) ? 'yes' : 'no'],
        ]);
        $accounts = ServiceAccount::where('user_id', $user->id)->get();
        if ($accounts->isEmpty()) {
            $this->warn('No service accounts enrolled for this user.');
        }
        foreach ($accounts as $account) {
            $this->table(['Service', 'Status', 'Next attempt', 'Credential expiry'], [[
                $account->service, $account->status,
                $account->retry_at?->toIso8601String() ?? '-',
                $account->credential_expires_at?->toIso8601String() ?? '-',
            ]]);
        }

        return self::SUCCESS;
    }
}
