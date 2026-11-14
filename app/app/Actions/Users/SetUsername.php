<?php

namespace App\Actions\Users;

use App\Concerns\PasswordValidationRules;
use App\Concerns\UsernameValidationRules;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A person chooses their username once; linked services start using it with the same password. */
class SetUsername
{
    use PasswordValidationRules, UsernameValidationRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'username' => ['required', ...$this->usernameRules()],
            'current_password' => $this->currentPasswordRules(),
        ];
    }

    public function handle(User $user, string $username, #[\SensitiveParameter] string $currentPassword): void
    {
        DB::transaction(function () use ($user, $username, $currentPassword): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->username !== null) {
                throw ValidationException::withMessages(['username' => 'El nombre de usuario ya está establecido.']);
            }
            $user->username = $username;
            $user->save();
            // Reuse the current password, including encrypted retry/expiry handling.
            app(ServiceAccounts::class)->changePassword($user, $currentPassword);
        });
    }
}
