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

    /** Only the person chooses their own photo. */
    public function changeAvatar(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    /** Whoever manages the person may take an unsuitable photo down. */
    public function removeAvatar(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $this->update($actor, $target);
    }

    /** Photos are seen by the person and by the administrators who list people. */
    public function viewAvatar(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $this->viewAny($actor);
    }
}
