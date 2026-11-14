<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\User;

class RevokeInvitation
{
    public function handle(User $issuer, Invitation $invitation): void
    {
        abort_unless($issuer->canInviteRole($invitation->role), 403);
        $invitation->expires_at = now();
        $invitation->save();
    }
}
