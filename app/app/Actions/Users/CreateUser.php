<?php

namespace App\Actions\Users;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\UsernameValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\ServiceAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manual account for someone who cannot receive an invitation. The administrator vouches
 * for the email (no message is sent) and hands over a temporary password that must be
 * changed on first access; service credentials are NOT provisioned with it.
 */
class CreateUser
{
    use PasswordValidationRules, ProfileValidationRules, UsernameValidationRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'username' => $this->usernameRules(),
            'email' => ['required', 'string', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'member'])],
            'password' => $this->passwordRules(),
            'services' => ['sometimes', 'array'],
            'services.*' => ['string', 'distinct', Rule::in(app(ServiceAccounts::class)->available())],
        ];
    }

    /** @param array{name: string, username?: ?string, email: string, role: string, password: string, services?: list<string>} $data validated with rules() */
    public function handle(User $actor, #[\SensitiveParameter] array $data): User
    {
        abort_unless($actor->canInviteRole($data['role']), 403);

        return DB::transaction(function () use ($actor, $data): User {
            $invitation = Invitation::where('email', $data['email'])->lockForUpdate()->first();
            abort_if($invitation && ! $actor->canInviteRole($invitation->role), 403);

            $user = new User;
            $user->name = $data['name'];
            $user->username = ($data['username'] ?? '') === '' ? null : $data['username'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->role = $data['role'];
            $user->service_access = $data['services'] ?? [];
            $user->services_revoked = app(ServiceAccounts::class)->notGranted($user->service_access);
            $user->email_verified_at = now();
            $user->must_change_password = true;
            $user->created_by_admin_id = $actor->id;
            $user->save();
            app(AdminAudit::class)->record($actor, $user, 'user.created');

            if ($invitation) {
                $invitation->expires_at = now();
                $invitation->save();
            }

            return $user;
        });
    }
}
