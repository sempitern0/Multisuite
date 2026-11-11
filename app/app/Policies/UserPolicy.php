<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return in_array($actor->role, ['owner', 'admin'], true);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $actor->canInviteRole($target->role);
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->update($actor, $target);
    }
}
