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
        return ['name' => $this->nameRules()];
    }

    /** @param array{name: string} $data validated with rules() */
    public function handle(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->name = $data['name'];
            $locked->save();
        });
    }
}
