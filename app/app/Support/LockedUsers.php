<?php

namespace App\Support;

use App\Models\User;

/**
 * Administrative operations re-check roles inside their transaction. Both rows are
 * locked in a stable order (by id) so two administrators cannot deadlock each other.
 */
final class LockedUsers
{
    /** @return array{User, User} actor and target, freshly read under lock */
    public static function pair(int $actorId, int $targetId): array
    {
        $users = User::whereIn('id', [$actorId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $users->get($actorId);
        $target = $users->get($targetId);
        abort_unless($actor instanceof User && $target instanceof User, 404);

        return [$actor, $target];
    }
}
