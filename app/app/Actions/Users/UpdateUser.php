<?php

namespace App\Actions\Users;

use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AdminAudit;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Changes name, email and role of another person, re-checking roles under lock. */
class UpdateUser
{
    use ProfileValidationRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(User $target): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role' => ['required', Rule::in(['member', 'admin'])],
            'email_confirmed' => ['sometimes', 'accepted'],
        ];
    }

    /** @param array{name: string, email: string, role: string, email_confirmed?: mixed} $data validated with rules() */
    public function handle(User $actor, User $target, array $data): void
    {
        DB::transaction(function () use ($actor, $target, $data): void {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('update', $target);
            abort_unless($actor->canInviteRole($data['role']), 403);
            $previousRole = $target->role;
            if ($target->email !== $data['email']) {
                if (! isset($data['email_confirmed'])) {
                    throw ValidationException::withMessages(['email_confirmed' => 'Confirma que has comprobado la titularidad del nuevo correo.']);
                }
                DB::table('pending_email_changes')->where('user_id', $target->id)->delete();
                Password::broker()->deleteToken($target);
                Invitation::whereIn('email', [$target->email, $data['email']])->update(['expires_at' => now()]);
                $target->email_verified_at = now();
            }
            $target->name = $data['name'];
            $target->email = $data['email'];
            $target->role = $data['role'];
            $changedFields = array_keys($target->getDirty());
            $target->save();
            if (array_intersect($changedFields, ['name', 'email', 'role']) !== []) {
                app(AdminAudit::class)->record($actor, $target, 'user.updated', $changedFields, $previousRole);
            }
        });
    }
}
