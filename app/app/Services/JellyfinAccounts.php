<?php

namespace App\Services;

use App\Enums\ServiceAccountStatus;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\Provisioning\CommonLibraries;
use App\Services\Provisioning\NeedsConnection;
use App\Services\Provisioning\PersonalLibraries;
use App\Services\Provisioning\ScansMediaChanges;
use App\Services\Provisioning\ServiceProvisioner;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * People's Vídeo accounts (Jellyfin 10.11, docs/JELLYFIN.md), once the owner has connected the
 * server (JellyfinServer). Each person gets a non-administrator account that sees only their
 * private library, the operator's common folder and what was published for everyone, with their
 * parental limit.
 */
final class JellyfinAccounts implements CommonLibraries, NeedsConnection, PersonalLibraries, ScansMediaChanges, ServiceProvisioner
{
    /** Operator's common folder inside the container (compose.video.yaml, read-only). */
    public const OPERATOR_COMMON = '/common';

    /** Age limits offered to administrators; Jellyfin 10.9+ rating scores follow the minimum age. */
    public const AGE_LIMITS = [7 => 'Hasta 7 años', 12 => 'Hasta 12 años', 16 => 'Hasta 16 años', 18 => 'Hasta 18 años'];

    private const AUTH_PROVIDER = 'Jellyfin.Server.Implementations.Users.DefaultAuthenticationProvider';

    private const RESET_PROVIDER = 'Jellyfin.Server.Implementations.Users.DefaultPasswordResetProvider';

    public function __construct(private JellyfinClient $jellyfin) {}

    public static function connected(): bool
    {
        return JellyfinClient::connected();
    }

    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $this->jellyfin->guard(function () use ($account, $password): void {
            $user = User::findOrFail($account->user_id);
            $name = $user->username ?? $account->username;
            $remote = $this->find($account);
            if ($remote === null) {
                if ($account->external_id !== null) {
                    throw new RuntimeException('conflict');
                }
                // Never adopt or reset a native account just because its name matches.
                if ($this->users()->contains(fn (array $row): bool => strcasecmp((string) $row['Name'], $name) === 0)) {
                    throw new RuntimeException('conflict');
                }
                // Created with a discarded random password: Jellyfin gives new accounts every library,
                // so nobody can sign in until the policy below is saved and verified.
                $remote = $this->jellyfin->check($this->jellyfin->admin()->post('/Users/New', ['Name' => $name, 'Password' => Str::random(64)]))->json();
                if (! is_array($remote) || ! is_string($remote['Id'] ?? null)) {
                    throw new RuntimeException('unavailable');
                }
                $account->external_id = $remote['Id'];
                $account->username = $name;
                // Remembered before any further call: a lost response must never create a second account.
                $account->save();
            }
            $this->assertIdentity($account, $remote, [$account->username, $name]);
            // Libraries and policy come first; the person's password only after they are verified.
            app(MediaLibrary::class)->directory('video', $account, null);
            $this->libraries($account);
            $this->policy($account, false);
            $this->password($account, $password);
            if ($remote['Name'] !== $name) {
                $this->rename($account, $name);
            }
            $login = $this->jellyfin->check($this->jellyfin->http()->post('/Users/AuthenticateByName', ['Username' => $name, 'Pw' => $password]));
            if ($login->json('User.Id') !== $account->external_id) {
                throw new RuntimeException('unavailable');
            }
            $this->jellyfin->http((string) $login->json('AccessToken'))->post('/Sessions/Logout');
            $account->username = $name;
        });
    }

    public function rename(ServiceAccount $account, string $username): void
    {
        $this->jellyfin->guard(function () use ($account, $username): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote, [$account->username, $username]);
            if ($remote['Name'] === $username) {
                return;
            }
            $response = $this->jellyfin->admin()->post('/Users?userId='.rawurlencode((string) $account->external_id), [...$remote, 'Name' => $username]);
            if (in_array($response->status(), [400, 409], true)) {
                throw new RuntimeException('conflict');
            }
            $this->jellyfin->check($response);
            if (($this->find($account)['Name'] ?? null) !== $username) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function lock(ServiceAccount $account): void
    {
        $this->jellyfin->guard(function () use ($account): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote, [$account->username]);
            // Revocation never depends on anything else: password first, then the disabled policy.
            $this->password($account, Str::random(64));
            $this->policy($account, true);
        });
    }

    public function remove(ServiceAccount $account): void
    {
        $this->jellyfin->guard(function () use ($account): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote, [$account->username]);
            $this->jellyfin->check($this->jellyfin->admin()->delete('/Users/'.rawurlencode((string) $account->external_id)));
            if ($this->find($account) !== null) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function ensurePersonalLibrary(ServiceAccount $account, ?string $section, string $label): void
    {
        $this->whileReady($account, function (ServiceAccount $locked): void {
            $this->libraries($locked);
            $this->policy($locked, false);
        });
    }

    public function ensureCommonLibrary(ServiceAccount $account, ?string $section, string $label): void
    {
        $this->whileReady($account, function (ServiceAccount $locked) use ($label): void {
            $ids = $locked->library_ids ?? [];
            $ids[CommonLibraries::DIRECTORY] = $this->library(PersonalLibraries::MOUNT.'/'.CommonLibraries::DIRECTORY, $label, 'homevideos');
            $locked->library_ids = $ids;
            $this->policy($locked, false);
        });
    }

    /** Re-applies the policy (parental limit) after an administrator changes it. */
    public function applyPolicy(ServiceAccount $account): void
    {
        $this->whileReady($account, fn (ServiceAccount $locked) => $this->policy($locked, false));
    }

    /** Serialised against suspension and revocation: a stale job must never restore access. */
    private function whileReady(ServiceAccount $account, callable $operation): void
    {
        $this->jellyfin->guard(function () use ($account, $operation): void {
            DB::transaction(function () use ($account, $operation): void {
                $locked = ServiceAccount::whereKey($account->id)->lockForUpdate()->first();
                $user = $locked === null ? null : User::find($locked->user_id);
                if ($locked === null || $locked->status !== ServiceAccountStatus::Ready->value || $user === null
                    || ! app(ServiceAccounts::class)->entitled($user, 'video')) {
                    return;
                }
                $operation($locked);
                $locked->save();
            });
        });
    }

    /** Finds or creates the person's library and the operator's common one (library_ids). */
    private function libraries(ServiceAccount $account): void
    {
        $ids = $account->library_ids ?? [];
        $ids['personal'] = $this->library(PersonalLibraries::MOUNT.'/'.strtolower($account->remote_id), 'Mis vídeos · '.substr($account->remote_id, 0, 8), 'homevideos');
        $ids['operator'] = $this->library(self::OPERATOR_COMMON, 'Vídeos para todos', 'movies');
        $account->library_ids = $ids;
    }

    private function password(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $this->jellyfin->check($this->jellyfin->admin()->post('/Users/Password?userId='.rawurlencode((string) $account->external_id), ['NewPw' => $password, 'ResetPassword' => false]));
    }

    /**
     * Non-administrator policy with exactly the libraries in library_ids (none when disabled) and
     * the parental limit. Writes only the policy: it never creates libraries. Merged into the
     * current policy so Jellyfin's own fields survive; verified after saving.
     */
    private function policy(ServiceAccount $account, bool $disabled): void
    {
        $remote = $this->find($account) ?? throw new RuntimeException('unavailable');
        $user = User::findOrFail($account->user_id);
        $folders = array_values(array_unique(array_map('strval', $disabled ? [] : ($account->library_ids ?? []))));
        sort($folders);
        $limit = $user->video_max_rating;
        $policy = [
            ...($remote['Policy'] ?? []),
            'IsAdministrator' => false,
            'IsDisabled' => $disabled,
            'IsHidden' => true,
            'EnableAllFolders' => false,
            'EnabledFolders' => $folders,
            'EnableContentDeletion' => false,
            'EnableContentDeletionFromFolders' => [],
            'EnableRemoteControlOfOtherUsers' => false,
            'EnableSharedDeviceControl' => false,
            'EnableCollectionManagement' => false,
            'EnableSubtitleManagement' => false,
            'EnableLiveTvManagement' => false,
            'EnablePublicSharing' => false,
            'EnableMediaPlayback' => ! $disabled,
            'MaxParentalRating' => $limit,
            // Unrated films and series are hidden from limited accounts; home videos stay visible.
            'BlockUnratedItems' => $limit === null ? [] : ['Movie', 'Trailer', 'Series', 'LiveTvChannel', 'LiveTvProgram', 'ChannelContent'],
            'AuthenticationProviderId' => $remote['Policy']['AuthenticationProviderId'] ?? self::AUTH_PROVIDER,
            'PasswordResetProviderId' => $remote['Policy']['PasswordResetProviderId'] ?? self::RESET_PROVIDER,
        ];
        $this->jellyfin->check($this->jellyfin->admin()->post('/Users/'.rawurlencode((string) $account->external_id).'/Policy', $policy));
        // Read again after saving (?? also covers an account that disappeared meanwhile).
        $saved = $this->find($account)['Policy'] ?? null;
        if (! is_array($saved)) {
            throw new RuntimeException('unavailable');
        }
        $enabled = array_map('strval', $saved['EnabledFolders'] ?? []);
        sort($enabled);
        if (($saved['IsAdministrator'] ?? true) !== false || ($saved['EnableAllFolders'] ?? true) !== false
            || $enabled !== $folders || ($saved['IsDisabled'] ?? null) !== $disabled || ($saved['MaxParentalRating'] ?? null) !== $limit) {
            throw new RuntimeException('unavailable');
        }
    }

    /** Finds or creates the library rooted at $path and returns its id. */
    private function library(string $path, string $name, string $type): string
    {
        $folders = collect((array) $this->jellyfin->check($this->jellyfin->admin()->get('/Library/VirtualFolders'))->json());
        $existing = $folders->first(fn ($row): bool => is_array($row) && in_array($path, array_map(fn ($location) => rtrim((string) $location, '/'), $row['Locations'] ?? []), true));
        if ($existing === null) {
            $query = http_build_query(['name' => $name, 'collectionType' => $type, 'paths' => $path, 'refreshLibrary' => 'true']);
            $this->jellyfin->check($this->jellyfin->admin()->post('/Library/VirtualFolders?'.$query, ['LibraryOptions' => [
                'EnableRealtimeMonitor' => true, 'EnablePhotos' => false, 'SaveLocalMetadata' => false,
                'EnableInternetProviders' => true, 'PathInfos' => [['Path' => $path]],
            ]]));
            $existing = collect((array) $this->jellyfin->check($this->jellyfin->admin()->get('/Library/VirtualFolders'))->json())
                ->first(fn ($row): bool => is_array($row) && in_array($path, array_map(fn ($location) => rtrim((string) $location, '/'), $row['Locations'] ?? []), true));
        }
        $id = is_array($existing) ? ($existing['ItemId'] ?? null) : null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('unavailable');
        }

        return $id;
    }

    public function scanMedia(): void
    {
        $this->jellyfin->guard(fn () => $this->jellyfin->check($this->jellyfin->admin()->post('/Library/Refresh')));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function users(): Collection
    {
        /** @var list<array<string, mixed>> $users */
        $users = array_values(array_filter((array) $this->jellyfin->check($this->jellyfin->admin()->get('/Users'))->json(), 'is_array'));

        return collect($users);
    }

    /** @return array<string, mixed>|null */
    private function find(ServiceAccount $account): ?array
    {
        if ($account->external_id === null) {
            return null;
        }

        return $this->users()->first(fn (array $row): bool => ($row['Id'] ?? null) === $account->external_id);
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  list<string|null>  $names  names the account may have (a rename may have landed before its response was lost)
     */
    private function assertIdentity(ServiceAccount $account, array $remote, array $names): void
    {
        if (($remote['Id'] ?? null) !== $account->external_id || ! in_array($remote['Name'] ?? null, $names, true)) {
            throw new RuntimeException('conflict');
        }
        // The portal never manages Jellyfin administrators, the connected one included.
        if (($remote['Policy']['IsAdministrator'] ?? false) === true) {
            throw new RuntimeException('conflict');
        }
    }
}
