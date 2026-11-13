<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFile;

/** Private by default: only the owner, never administrators implicitly. */
class UserFilePolicy
{
    public function view(User $actor, UserFile $file): bool
    {
        return $actor->id === $file->user_id;
    }

    public function update(User $actor, UserFile $file): bool
    {
        return $this->view($actor, $file);
    }
}
