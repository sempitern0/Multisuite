<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

class FolderPolicy
{
    public function view(User $actor, Folder $folder): bool
    {
        return $actor->id === $folder->user_id;
    }

    public function update(User $actor, Folder $folder): bool
    {
        return $this->view($actor, $folder);
    }
}
