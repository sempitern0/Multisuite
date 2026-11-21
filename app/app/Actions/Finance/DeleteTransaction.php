<?php

namespace App\Actions\Finance;

use App\Models\PersonalTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Removes one movement; the receipt stays in Mis archivos. False when it no longer exists. */
class DeleteTransaction
{
    public function handle(User $actor, int $id): bool
    {
        $transaction = PersonalTransaction::ownedBy($actor)->find($id);
        if ($transaction === null) {
            return false;
        }
        Gate::forUser($actor)->authorize('delete', $transaction);
        $transaction->delete();

        return true;
    }
}
