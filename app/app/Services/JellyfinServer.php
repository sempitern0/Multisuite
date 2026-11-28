<?php

namespace App\Services;

use App\Enums\ServiceAuditAction;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Jellyfin server as a whole (docs/JELLYFIN.md): the owner's one-off connection (startup wizard
 * or an existing administrator, API key kept encrypted), its sign-in notice, hardware acceleration
 * among what the host offers, and retiring idle browser sessions. People's accounts: JellyfinAccounts.
 */
final class JellyfinServer
{
    /** Hardware acceleration choices offered per detected GPU vendor (make video-detect). */
    public const ACCELERATION = [
        'none' => 'Procesador (sin aceleración)',
        'qsv' => 'Intel Quick Sync',
        'vaapi' => 'VA-API (Intel o AMD)',
        'nvenc' => 'NVIDIA NVENC',
    ];

    /** @var array<string, list<string>> */
    private const BY_VENDOR = ['intel' => ['qsv', 'vaapi'], 'amd' => ['vaapi'], 'nvidia' => ['nvenc']];

    public function __construct(private JellyfinClient $jellyfin) {}

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

    /** empty (wizard pending), existing (set up, not connected), connected or unavailable. Never discloses identities. */
    public function setupState(): string
    {
        try {
            $info = $this->jellyfin->http()->get('/System/Info/Public');
            $completed = $info->json('StartupWizardCompleted');
            if (! $info->successful() || ! is_bool($completed)) {
                return 'unavailable';
            }

            return $completed ? (JellyfinClient::connected() ? 'connected' : 'existing') : 'empty';
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
        abort_unless($owner->isOwner() && ! $owner->isSuspended() && $owner->hasVerifiedEmail(), 403);
        Cache::lock('video-connection', 60)->block(10, function () use ($owner, $name, $password): void {
            $this->jellyfin->guard(function () use ($owner, $name, $password): void {
                if ($this->setupState() === 'empty') {
                    $this->jellyfin->stage($this->jellyfin->http()->post('/Startup/Configuration', [
                        'UICulture' => 'es-ES', 'MetadataCountryCode' => 'ES', 'PreferredMetadataLanguage' => 'es',
                    ]), 'wizard');
                    $this->jellyfin->stage($this->jellyfin->http()->get('/Startup/User'), 'wizard');
                    $this->jellyfin->stage($this->jellyfin->http()->post('/Startup/User', ['Name' => $name, 'Password' => $password]), 'wizard');
                    $this->jellyfin->stage($this->jellyfin->http()->post('/Startup/RemoteAccess', ['EnableRemoteAccess' => false, 'EnableAutomaticPortMapping' => false]), 'wizard');
                    $this->jellyfin->stage($this->jellyfin->http()->post('/Startup/Complete'), 'wizard');
                }
                $login = $this->jellyfin->stage($this->jellyfin->http()->post('/Users/AuthenticateByName', ['Username' => $name, 'Pw' => $password]), 'login');
                $token = $login->json('AccessToken');
                $native = $login->json('User');
                if (! is_string($token) || $token === '') {
                    throw new RuntimeException('not_admin');
                }
                $admin = $this->jellyfin->http($token);
                try {
                    if (! is_array($native) || ($native['Policy']['IsAdministrator'] ?? false) !== true) {
                        throw new RuntimeException('not_admin');
                    }
                    $key = $this->connectionKey($admin);
                } finally {
                    $admin->post('/Sessions/Logout');
                }
                DB::transaction(function () use ($owner, $native, $key): void {
                    $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                    abort_unless($locked->isOwner() && ! $locked->isSuspended(), 403);
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

    /** Keep the installed key when reauthorising against the same server. */
    private function connectionKey(PendingRequest $admin): string
    {
        $current = ServiceConnection::find('video')?->secret;
        if (is_string($current) && $current !== '') {
            $keys = $this->jellyfin->stage($admin->get('/Auth/Keys'), 'key')->json('Items');
            if (is_array($keys) && in_array($current, array_column($keys, 'AccessToken'), true)) {
                return $current;
            }
        }

        $name = 'Multisuite '.Str::lower(Str::random(8));
        $this->jellyfin->stage($admin->post('/Auth/Keys?app='.rawurlencode($name)), 'key');
        $key = collect((array) $this->jellyfin->stage($admin->get('/Auth/Keys'), 'key')->json('Items'))
            ->first(fn ($row): bool => is_array($row) && ($row['AppName'] ?? null) === $name)['AccessToken'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('unavailable');
        }

        return $key;
    }

    /**
     * Jellyfin's sign-in page tells people which credentials to use. Cosmetic: a failure is only
     * logged (the look itself comes from the gateway, docker/video-proxy).
     */
    public function brand(): void
    {
        try {
            $branding = $this->jellyfin->check($this->jellyfin->admin()->get('/System/Configuration/branding'))->json();
            $branding = is_array($branding) ? $branding : [];
            $branding['LoginDisclaimer'] = 'Entra con tu usuario y contraseña de Multisuite.';
            $branding['SplashscreenEnabled'] = false;
            $this->jellyfin->check($this->jellyfin->admin()->post('/System/Configuration/branding', $branding));
        } catch (ConnectionException|RuntimeException $exception) {
            Log::warning('video branding not applied', ['reason' => $exception->getMessage()]);
        }
    }

    /** Existing people activate their account on their next sign-in (Laravel hashes cannot be replayed). */
    public function reserveExisting(): void
    {
        if (! config('provisioning.video.enabled') || ! JellyfinClient::connected()) {
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
            $type = $this->jellyfin->check($this->jellyfin->admin()->get('/System/Configuration/encoding'))->json('HardwareAccelerationType');

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
        $this->jellyfin->guard(function () use ($type): void {
            $options = $this->jellyfin->check($this->jellyfin->admin()->get('/System/Configuration/encoding'))->json();
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
            $this->jellyfin->check($this->jellyfin->admin()->post('/System/Configuration/encoding', $options));
            if ($this->jellyfin->check($this->jellyfin->admin()->get('/System/Configuration/encoding'))->json('HardwareAccelerationType') !== $type) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    /**
     * Retires browser sessions the portal opened that have been idle for $days: the device and its
     * token disappear from Jellyfin, so a forgotten browser cannot keep using it. Returns how many.
     */
    public function pruneBrowserSessions(int $days): int
    {
        $removed = 0;
        $this->jellyfin->guard(function () use ($days, &$removed): void {
            $cutoff = now()->subDays($days);
            foreach ((array) $this->jellyfin->check($this->jellyfin->admin()->get('/Devices'))->json('Items') as $device) {
                $id = is_array($device) ? ($device['Id'] ?? null) : null;
                $active = is_array($device) && is_string($device['DateLastActivity'] ?? null) ? Carbon::parse($device['DateLastActivity']) : null;
                if (! is_string($id) || ! str_starts_with($id, JellyfinClient::DEVICE_PREFIX) || ($active !== null && $active->isAfter($cutoff))) {
                    continue;
                }
                $this->jellyfin->check($this->jellyfin->admin()->delete('/Devices?id='.rawurlencode($id)));
                $removed++;
            }
        });

        return $removed;
    }
}
