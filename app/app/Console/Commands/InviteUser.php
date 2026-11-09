<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InviteUser extends Command
{
    protected $signature = 'portal:invite {email} {--days=7} {--revoke}';

    protected $description = 'Create or revoke a private portal invitation';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $days = $this->option('days');
        $validator = Validator::make(['email' => $email, 'days' => $days], [
            'email' => ['required', 'email', 'max:255'],
            'days' => ['required', 'integer', 'between:1,30'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            DB::table('invitations')->where('email', $email)->delete();
            $this->info('Invitation revoked. Existing accounts are unchanged.');

            return self::SUCCESS;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('An account already exists for this email.');

            return self::FAILURE;
        }

        $token = Str::random(64);
        DB::table('invitations')->updateOrInsert(['email' => $email], [
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) $days),
            'used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->info('Share this code privately with the invited email owner. It is shown only now.');
        $this->line($token);
        $this->line(route('register'));

        return self::SUCCESS;
    }
}
