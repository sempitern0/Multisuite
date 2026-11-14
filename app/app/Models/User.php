<?php

namespace App\Models;

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
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property list<string>|null $service_access
 * @property array<string, mixed>|null $preferences
 * @property int $id
 * @property string $role
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
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
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @return HasMany<ServiceAccount, $this> */
    public function serviceAccounts(): HasMany
    {
        return $this->hasMany(ServiceAccount::class);
    }

    public function canInviteRole(string $role): bool
    {
        return ($this->role === 'owner' && in_array($role, ['admin', 'member'], true))
            || ($this->role === 'admin' && $role === 'member');
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
            'preferences' => 'array',
            'must_change_password' => 'boolean',
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
        return match ($this->role) {
            'owner' => 'Propietario',
            'admin' => 'Administrador',
            'member' => 'Miembro',
            default => 'Sin rol',
        };
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
