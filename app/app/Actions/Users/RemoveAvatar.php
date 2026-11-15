<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Users\Avatars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** The person, or whoever manages them, removes the photo; initials are shown instead. */
class RemoveAvatar
{
    public function __construct(private Avatars $avatars, private AdminAudit $audit) {}

    public function handle(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('removeAvatar', $locked);
            if ($locked->avatar_hash === null) {
                return;
            }
            $locked->avatar_hash = null;
            $locked->save();
            if ($actor->id !== $locked->id) {
                $this->audit->record($actor, $locked, 'user.avatar_removed');
            }
            DB::afterCommit(fn () => $this->avatars->forget($locked->id));
        });
    }
}
