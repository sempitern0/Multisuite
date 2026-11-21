<?php

namespace App\Policies;

use App\Models\PersonalTransaction;
use App\Models\User;

/** Personal finances are private: administration grants no access to someone else's. */
class PersonalTransactionPolicy
{
    public function update(User $user, PersonalTransaction $transaction): bool
    {
        return $transaction->user_id === $user->id;
    }

    public function delete(User $user, PersonalTransaction $transaction): bool
    {
        return $transaction->user_id === $user->id;
    }
}
