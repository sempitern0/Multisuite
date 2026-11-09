<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class BootstrapOwner extends Command
{
    use PasswordValidationRules;

    protected $signature = 'portal:owner {email} {--create : Create the initial owner interactively on a fresh installation}';

    protected $description = 'Bootstrap the first owner from an existing verified account (trusted server console only)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('Invalid email.');

            return self::FAILURE;
        }
        if (User::where('role', 'owner')->exists()) {
            $this->error('An owner already exists. This command is only for initial bootstrap.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->first();
        if ($user === null && $this->option('create')) {
            $data = [
                'name' => $this->ask('Owner name'),
                'password' => $this->secret('Owner password'),
                'password_confirmation' => $this->secret('Confirm password'),
            ];
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'password' => $this->passwordRules(),
            ]);
            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }
            $user = new User;
            $user->name = (string) $data['name'];
            $user->email = $email;
            $user->password = (string) $data['password'];
            // The trusted operator establishes the initial administrative identity.
            $user->email_verified_at = now();
        }
        if ($user === null || $user->hasVerifiedEmail() === false) {
            $this->error('An existing verified account is required. No account has been created or verified.');

            return self::FAILURE;
        }
        $user->role = 'owner';
        $user->save();
        $this->info('Initial owner configured. Sign in to manage invitations.');

        return self::SUCCESS;
    }
}
