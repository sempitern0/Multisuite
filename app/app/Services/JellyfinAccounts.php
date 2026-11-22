<?php

namespace App\Services;

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceAuditAction;
use App\Models\ServiceAccount;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\Provisioning\CommonLibraries;
use App\Services\Provisioning\NeedsConnection;
use App\Services\Provisioning\PersonalLibraries;
use App\Services\Provisioning\ServiceProvisioner;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Vídeo (Jellyfin 10.11, docs/JELLYFIN.md). The owner connects it once (the portal completes the
 * startup wizard on an empty server or proves an existing administrator) and the portal keeps an
 * API key. Each person gets a non-administrator account that sees only their private library, the
 * operator's common folder and what was published for everyone, with their parental limit.
 */
final class JellyfinAccounts implements CommonLibraries, NeedsConnection, PersonalLibraries, ServiceProvisioner
{
    /** Operator's common folder inside the container (compose.video.yaml, read-only). */
    public const OPERATOR_COMMON = '/common';

    /** Hardware acceleration choices offered per detected GPU vendor (make video-detect). */
    public const ACCELERATION = [
        'none' => 'Procesador (sin aceleración)',
        'qsv' => 'Intel Quick Sync',
        'vaapi' => 'VA-API (Intel o AMD)',
        'nvenc' => 'NVIDIA NVENC',
    ];

    /** @var array<string, list<string>> */
    private const BY_VENDOR = ['intel' => ['qsv', 'vaapi'], 'amd' => ['vaapi'], 'nvidia' => ['nvenc']];

    /** Age limits offered to administrators; Jellyfin 10.9+ rating scores follow the minimum age. */
    public const AGE_LIMITS = [7 => 'Hasta 7 años', 12 => 'Hasta 12 años', 16 => 'Hasta 16 años', 18 => 'Hasta 18 años'];

    private const AUTH_PROVIDER = 'Jellyfin.Server.Implementations.Users.DefaultAuthenticationProvider';

    private const RESET_PROVIDER = 'Jellyfin.Server.Implementations.Users.DefaultPasswordResetProvider';

    private const CLIENT = 'MediaBrowser Client="Multisuite", Device="Portal", DeviceId="multisuite-portal", Version="1.0.0"';

    public static function connected(): bool
    {
        return ServiceConnection::whereKey('video')->exists();
    }

    /** GPU vendor detected on the host by make video-detect (intel, amd, nvidia or none). */
    public static function vendor(): string
    {
        $vendor = (string) config('provisioning.video.hardware', 'none');

        return array_key_exists($vendor, self::BY_VENDOR) ? $vendor : 'none';
    }

    /** @return array<string, string> choices valid for this host; the first one is the default. */
    public static function accelerationChoices(): array
    {
        $choices = [];
        foreach ([...(self::BY_VENDOR[self::vendor()] ?? []), 'none'] as $key) {
            $choices[$key] = self::ACCELERATION[$key];
        }

        return $choices;
    }

    /** One Authorization header (Jellyfin's MediaBrowser scheme), with a token when given. */
    public function http(#[\SensitiveParameter] ?string $token = null): PendingRequest
    {
        return Http::baseUrl(ServiceUrl::validated((string) config('portal.video_internal_url')))
            ->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(2)->timeout(10)
            ->replaceHeaders(['Authorization' => self::CLIENT.($token === null ? '' : ', Token="'.$token.'"')]);
    }

    public function client(): PendingRequest
    {
        $connection = ServiceConnection::find('video') ?? throw new RuntimeException('configuration');

        return $this->http($connection->secret);
    }

    /** empty (wizard pending), existing (set up, not connected), connected or unavailable. Never discloses identities. */
    public function setupState(): string
    {
        try {
            $info = $this->http()->get('/System/Info/Public');
            $completed = $info->json('StartupWizardCompleted');
            if (! $info->successful() || ! is_bool($completed)) {
                return 'unavailable';
            }

            return $completed ? (self::connected() ? 'connected' : 'existing') : 'empty';
        } catch (ConnectionException|RuntimeException) {
            return 'unavailable';
        }
    }

    /**
     * Authorise the integration once: on an empty server the owner's chosen name and password become
     * its administrator; otherwise they must belong to an existing administrator.
     */
    public function connect(User $owner, string $name, #[\SensitiveParameter] string $password): void
    {
        abort_unless($owner->role === 'owner' && ! $owner->isSuspended() && $owner->hasVerifiedEmail(), 403);
        Cache::lock('video-connection', 60)->block(10, function () use ($owner, $name, $password): void {
            $this->guard(function () use ($owner, $name, $password): void {
                if ($this->setupState() === 'empty') {
                    $this->stage($this->http()->post('/Startup/Configuration', [
                        'UICulture' => 'es-ES', 'MetadataCountryCode' => 'ES', 'PreferredMetadataLanguage' => 'es',
                    ]), 'wizard');
                    $this->stage($this->http()->get('/Startup/User'), 'wizard');
                    $this->stage($this->http()->post('/Startup/User', ['Name' => $name, 'Password' => $password]), 'wizard');
                    $this->stage($this->http()->post('/Startup/RemoteAccess', ['EnableRemoteAccess' => false, 'EnableAutomaticPortMapping' => false]), 'wizard');
                    $this->stage($this->http()->post('/Startup/Complete'), 'wizard');
                }
                $login = $this->stage($this->http()->post('/Users/AuthenticateByName', ['Username' => $name, 'Pw' => $password]), 'login');
                $token = $login->json('AccessToken');
                $native = $login->json('User');
                if (! is_string($token) || ! is_array($native) || ($native['Policy']['IsAdministrator'] ?? false) !== true) {
                    throw new RuntimeException('not_admin');
                }
                $admin = $this->http($token);
                $app = 'Multisuite '.Str::lower(Str::random(8));
                $this->stage($admin->post('/Auth/Keys?app='.rawurlencode($app)), 'key');
                $key = collect((array) $this->stage($admin->get('/Auth/Keys'), 'key')->json('Items'))
                    ->first(fn ($row): bool => is_array($row) && ($row['AppName'] ?? null) === $app)['AccessToken'] ?? null;
                $admin->post('/Sessions/Logout');
                if (! is_string($key) || $key === '') {
                    throw new RuntimeException('unavailable');
                }
                DB::transaction(function () use ($owner, $native, $key): void {
                    $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                    abort_unless($locked->role === 'owner' && ! $locked->isSuspended(), 403);
                    $connection = ServiceConnection::find('video') ?? new ServiceConnection;
                    $connection->service = 'video';
                    $connection->operator_id = (string) $native['Id'];
                    $connection->secret = $key;
                    $connection->save();
                    app(AdminAudit::class)->recordService($locked, $locked, 'video', ServiceAuditAction::AccountLinked);
                });
                $this->applyAcceleration(array_key_first(self::accelerationChoices()));
                $this->brand();
            });
        });
        $this->reserveExisting();
    }

    /**
     * Jellyfin's sign-in page tells people which credentials to use. Cosmetic: a failure is only
     * logged (the look itself comes from the gateway, docker/video-proxy).
     */
    public function brand(): void
    {
        try {
            $branding = $this->check($this->client()->get('/System/Configuration/branding'))->json();
            $branding = is_array($branding) ? $branding : [];
            $branding['LoginDisclaimer'] = 'Entra con tu usuario y contraseña de Multisuite.';
            $branding['SplashscreenEnabled'] = false;
            $this->check($this->client()->post('/System/Configuration/branding', $branding));
        } catch (ConnectionException|RuntimeException $exception) {
            Log::warning('video branding not applied', ['reason' => $exception->getMessage()]);
        }
    }

    /** Existing people activate their account on their next sign-in (Laravel hashes cannot be replayed). */
    public function reserveExisting(): void
    {
        if (! config('provisioning.video.enabled') || ! self::connected()) {
            return;
        }
        User::orderBy('id')->each(function (User $user): void {
            DB::transaction(function () use ($user): void {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $accounts = app(ServiceAccounts::class);
                $accounts->grantNewServices($locked);
                $accounts->awaitPassword($locked);
            });
        });
    }

    /** Current hardware acceleration in Jellyfin ('none' when it cannot be read). */
    public function acceleration(): string
    {
        try {
            $type = $this->check($this->client()->get('/System/Configuration/encoding'))->json('HardwareAccelerationType');

            return is_string($type) && array_key_exists($type, self::ACCELERATION) ? $type : 'none';
        } catch (ConnectionException|RuntimeException) {
            return 'none';
        }
    }

    /** Applies one of accelerationChoices() through Jellyfin's own encoding settings. */
    public function applyAcceleration(string $type): void
    {
        if (! array_key_exists($type, self::accelerationChoices())) {
            throw new RuntimeException('configuration');
        }
        $this->guard(function () use ($type): void {
            $options = $this->check($this->client()->get('/System/Configuration/encoding'))->json();
            if (! is_array($options)) {
                throw new RuntimeException('unavailable');
            }
            $hardware = $type !== 'none';
            $options['HardwareAccelerationType'] = $type;
            $options['EnableHardwareEncoding'] = $hardware;
            // Decoders every supported GPU of the last decade handles; Jellyfin falls back to the CPU otherwise.
            $options['HardwareDecodingCodecs'] = $hardware ? ['h264', 'hevc', 'mpeg2video', 'vc1', 'vp9'] : [];
            if (in_array($type, ['vaapi', 'qsv'], true)) {
                $options['VaapiDevice'] = '/dev/dri/renderD128';
            }
            $this->check($this->client()->post('/System/Configuration/encoding', $options));
            if ($this->check($this->client()->get('/System/Configuration/encoding'))->json('HardwareAccelerationType') !== $type) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $this->guard(function () use ($account, $password): void {
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
                $remote = $this->check($this->client()->post('/Users/New', ['Name' => $name, 'Password' => Str::random(64)]))->json();
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
            $login = $this->check($this->http()->post('/Users/AuthenticateByName', ['Username' => $name, 'Pw' => $password]));
            if ($login->json('User.Id') !== $account->external_id) {
                throw new RuntimeException('unavailable');
            }
            $this->http((string) $login->json('AccessToken'))->post('/Sessions/Logout');
            $account->username = $name;
        });
    }

    public function rename(ServiceAccount $account, string $username): void
    {
        $this->guard(function () use ($account, $username): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote, [$account->username, $username]);
            if ($remote['Name'] === $username) {
                return;
            }
            $response = $this->client()->post('/Users?userId='.rawurlencode((string) $account->external_id), [...$remote, 'Name' => $username]);
            if (in_array($response->status(), [400, 409], true)) {
                throw new RuntimeException('conflict');
            }
            $this->check($response);
            if (($this->find($account)['Name'] ?? null) !== $username) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function lock(ServiceAccount $account): void
    {
        $this->guard(function () use ($account): void {
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
        $this->guard(function () use ($account): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote, [$account->username]);
            $this->check($this->client()->delete('/Users/'.rawurlencode((string) $account->external_id)));
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
            $this->refresh($locked->library_ids['personal'] ?? null);
        });
    }

    public function ensureCommonLibrary(ServiceAccount $account, ?string $section, string $label): void
    {
        $this->whileReady($account, function (ServiceAccount $locked) use ($label): void {
            $ids = $locked->library_ids ?? [];
            $ids[CommonLibraries::DIRECTORY] = $this->library(PersonalLibraries::MOUNT.'/'.CommonLibraries::DIRECTORY, $label, 'homevideos');
            $locked->library_ids = $ids;
            $this->policy($locked, false);
            $this->refresh($ids[CommonLibraries::DIRECTORY]);
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
        $this->guard(function () use ($account, $operation): void {
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
        $this->check($this->client()->post('/Users/Password?userId='.rawurlencode((string) $account->external_id), ['NewPw' => $password, 'ResetPassword' => false]));
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
        $this->check($this->client()->post('/Users/'.rawurlencode((string) $account->external_id).'/Policy', $policy));
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
        $folders = collect((array) $this->check($this->client()->get('/Library/VirtualFolders'))->json());
        $existing = $folders->first(fn ($row): bool => is_array($row) && in_array($path, array_map(fn ($location) => rtrim((string) $location, '/'), $row['Locations'] ?? []), true));
        if ($existing === null) {
            $query = http_build_query(['name' => $name, 'collectionType' => $type, 'paths' => $path, 'refreshLibrary' => 'true']);
            $this->check($this->client()->post('/Library/VirtualFolders?'.$query, ['LibraryOptions' => [
                'EnableRealtimeMonitor' => true, 'EnablePhotos' => false, 'SaveLocalMetadata' => false,
                'EnableInternetProviders' => true, 'PathInfos' => [['Path' => $path]],
            ]]));
            $existing = collect((array) $this->check($this->client()->get('/Library/VirtualFolders'))->json())
                ->first(fn ($row): bool => is_array($row) && in_array($path, array_map(fn ($location) => rtrim((string) $location, '/'), $row['Locations'] ?? []), true));
        }
        $id = is_array($existing) ? ($existing['ItemId'] ?? null) : null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('unavailable');
        }

        return $id;
    }

    private function refresh(?string $libraryId): void
    {
        if ($libraryId !== null) {
            $this->check($this->client()->post('/Items/'.rawurlencode($libraryId).'/Refresh?Recursive=true&MetadataRefreshMode=Default&ImageRefreshMode=Default'));
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function users(): Collection
    {
        /** @var list<array<string, mixed>> $users */
        $users = array_values(array_filter((array) $this->check($this->client()->get('/Users'))->json(), 'is_array'));

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

    private function stage(Response $response, string $stage): Response
    {
        if ($response->status() === 429) {
            throw new RuntimeException('rate_limited');
        }
        if (! $response->successful()) {
            throw new RuntimeException(match (true) {
                $stage === 'login' && $response->status() === 401 => 'credentials',
                $stage === 'key' && in_array($response->status(), [401, 403], true) => 'not_admin',
                $stage === 'wizard' => 'wizard',
                default => 'unavailable',
            });
        }

        return $response;
    }

    private function check(Response $response): Response
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('configuration');
        }
        if (! $response->successful()) {
            throw new RuntimeException('unavailable');
        }

        return $response;
    }

    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }
}
