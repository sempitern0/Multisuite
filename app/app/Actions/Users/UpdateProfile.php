<?php

namespace App\Actions\Users;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A person changes their own name or email; a new email must be verified again. */
class UpdateProfile
{
    use ProfileValidationRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(User $user): array
    {
        return $this->profileRules($user->id);
    }

    /** @param array{name: string, email: string} $data validated with rules() */
    public function handle(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->name = $data['name'];
            $locked->email = $data['email'];
            if ($locked->isDirty('email')) {
                $locked->email_verified_at = null;
            }
            $locked->save();
        });
    }
}
