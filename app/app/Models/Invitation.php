<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class Invitation extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function isAcceptable(): bool
    {
        $issuer = User::find($this->invited_by);

        return $this->used_at === null && $this->expires_at->isFuture()
            && $issuer !== null && $issuer->hasVerifiedEmail() && $issuer->canInviteRole($this->role);
    }
}
