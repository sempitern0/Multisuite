<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string>|null $services
 * @property int $id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $used_at
 * @property-read User|null $inviter
 */
class Invitation extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['services' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The link still works: unused, not expired and issued by someone who can still
     * invite that role. scopeInStatus('pending') is the query form of the same rule.
     */
    public function isAcceptable(): bool
    {
        $issuer = $this->inviter;

        return $this->used_at === null && $this->expires_at->isFuture()
            && $issuer !== null && $issuer->hasVerifiedEmail() && $issuer->canInviteRole($this->role);
    }

    /** @return 'accepted'|'pending'|'expired' */
    public function status(): string
    {
        return match (true) {
            $this->used_at !== null => 'accepted',
            $this->isAcceptable() => 'pending',
            default => 'expired',
        };
    }

    /**
     * Filters by status() in SQL. '' (or an unknown value) means no filter.
     *
     * @param  Builder<Invitation>  $query
     */
    public function scopeInStatus(Builder $query, string $status): void
    {
        if ($status === 'accepted') {
            $query->whereNotNull('used_at');
        } elseif ($status === 'pending') {
            $query->whereNull('used_at')->where('expires_at', '>', now())
                ->whereHas('inviter', function (Builder $issuer): void {
                    self::canStillInvite($issuer);
                });
        } elseif ($status === 'expired') {
            $query->whereNull('used_at')->where(fn (Builder $inner) => $inner
                ->where('expires_at', '<=', now())
                ->orWhereDoesntHave('inviter', function (Builder $issuer): void {
                    self::canStillInvite($issuer);
                }));
        }
    }

    /**
     * Mirrors User::hasVerifiedEmail() and User::canInviteRole() against the invitation row.
     *
     * @param  Builder<User>  $issuer
     */
    private static function canStillInvite(Builder $issuer): void
    {
        $issuer->whereNotNull('users.email_verified_at')->where(fn (Builder $roles) => $roles
            ->where('users.role', 'owner')->whereIn('invitations.role', ['admin', 'member'])
            ->orWhere(fn (Builder $admin) => $admin->where('users.role', 'admin')->where('invitations.role', 'member')));
    }
}
