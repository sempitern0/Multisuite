<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\Users\Avatars;
use App\Support\ProfileStickers;
use App\Support\UserPreferences;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property list<string>|null $service_access
 * @property list<string>|null $services_revoked
 * @property array<string, mixed>|null $preferences
 * @property int $assistant_content_revision
 * @property int $id
 * @property string $role
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $avatar_sticker
 * @property string|null $avatar_hash
 * @property CarbonInterface|null $suspended_at
 * @property int|null $video_max_rating
 * @property bool $must_change_password
 * @property int|null $created_by_admin_id
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @return HasMany<ServiceAccount, $this> */
    public function serviceAccounts(): HasMany
    {
        return $this->hasMany(ServiceAccount::class);
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            // Only when nobody chose: an explicit value (also null for initials) is kept.
            if (! array_key_exists('avatar_sticker', $user->getAttributes())) {
                $user->avatar_sticker = array_rand(ProfileStickers::LABELS);
            }
        });

        // Any deletion path (administration or the person's own) takes the photo with it.
        static::deleted(function (User $user): void {
            if ($user->avatar_hash !== null) {
                DB::afterCommit(fn () => app(Avatars::class)->forget($user->id));
            }
        });
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Cache-safe address of the current photo: the hash changes with every new photo. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_hash === null ? ProfileStickers::url($this->avatar_sticker) : route('avatars.show', ['user' => $this->id, 'hash' => $this->avatar_hash]);
    }

    public function canInviteRole(string $role): bool
    {
        return ($this->role === Role::Owner->value && in_array($role, [Role::Admin->value, Role::Member->value], true))
            || ($this->role === Role::Admin->value && $role === Role::Member->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'service_access' => 'array',
            'services_revoked' => 'array',
            'preferences' => 'array',
            'must_change_password' => 'boolean',
            'suspended_at' => 'datetime',
            'video_max_rating' => 'integer',
        ];
    }

    /**
     * Case-insensitive search by name, email or username (shared by Personas and Almacenamiento).
     *
     * @param  Builder<User>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $pattern = '%'.Str::lower($term).'%';
        $query->where(function (Builder $search) use ($pattern): void {
            $search->whereRaw('LOWER(name) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(email) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(username) LIKE ?', [$pattern]);
        });
    }

    public function preferences(): UserPreferences
    {
        return UserPreferences::from($this->preferences);
    }

    /** Human role name shown in the interface (single source for every view). */
    public function roleLabel(): string
    {
        return Role::tryFrom($this->role)?->label() ?? 'Sin rol';
    }

    /** How much the role can do, for the role badge: owner 3, admin 2, member 1. */
    public function roleLevel(): int
    {
        return Role::tryFrom($this->role)?->level() ?? 0;
    }

    /**
     * Roles this person may give to someone else, with their labels (derived from canInviteRole()).
     *
     * @return array<string, string>
     */
    public function assignableRoles(): array
    {
        return array_filter(
            [Role::Member->value => Role::Member->label(), Role::Admin->value => Role::Admin->label()],
            fn (string $role): bool => $this->canInviteRole($role),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
