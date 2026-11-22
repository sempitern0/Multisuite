<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opening Vídeo without a second sign-in (docs/JELLYFIN.md § Entrada sin segundo inicio de sesión).
 * At sign-in, while the portal holds the password, it asks Jellyfin for a session of that person's
 * own account and keeps only its token, encrypted in the portal session. Opening Vídeo hands it,
 * through a one-minute single-use ticket, to a page on the gateway's origin that stores it where
 * Jellyfin's web interface keeps its own login. Never a password, never another person's account.
 */
final class VideoSession
{
    public function __construct(private JellyfinAccounts $jellyfin) {}

    public static function allowed(User $user): bool
    {
        return (bool) config('portal.services.video.enabled')
            && config('provisioning.video.enabled')
            && JellyfinAccounts::connected()
            && ! $user->isSuspended()
            && ! $user->must_change_password
            && app(ServiceAccounts::class)->entitled($user, 'video')
            && ServiceAccount::where('user_id', $user->id)->where('service', 'video')->where('status', 'ready')->exists();
    }

    public function prepare(User $user, #[\SensitiveParameter] string $password): void
    {
        $this->forget();
        if (! config('portal.services.video.enabled') || ! config('provisioning.video.enabled') || ! JellyfinAccounts::connected()
            || $user->isSuspended() || $user->must_change_password || ! app(ServiceAccounts::class)->entitled($user, 'video')) {
            return;
        }
        try {
            // The account must exist with this password before Jellyfin can sign it in.
            app(ServiceAccounts::class)->resume($user, $password);
            $account = ServiceAccount::where('user_id', $user->id)->where('service', 'video')->first();
            if ($account === null) {
                return;
            }
            if ($account->status !== 'ready') {
                app(ServiceAccounts::class)->synchronize($account->id);
                $account->refresh();
            }
            if (! self::allowed($user) || $account->external_id === null) {
                return;
            }
            $device = 'multisuite-'.Str::lower(Str::random(24));
            $login = $this->jellyfin->http(null, $device)->post('/Users/AuthenticateByName', ['Username' => $account->username, 'Pw' => $password]);
            $token = $login->json('AccessToken');
            if (! $login->successful() || ! is_string($token) || ! $this->matches($account, $login->json('User'))) {
                return;
            }
            $server = $this->jellyfin->http()->get('/System/Info/Public');
            session()->put('video.login', Crypt::encrypt([
                'user' => $user->id, 'password_hash' => hash('sha256', (string) $user->password),
                'token' => $token, 'remote' => $account->external_id, 'device' => $device,
                'server' => (string) $server->json('Id'), 'name' => (string) $server->json('ServerName'),
            ]));
        } catch (ConnectionException|RuntimeException) {
            // A service outage must never prevent signing in to Multisuite.
        }
    }

    public function issue(User $user): string
    {
        abort_unless(self::allowed($user), 403);
        $ticket = Str::random(64);
        Cache::put('video-launch:'.hash('sha256', $ticket), Crypt::encrypt([
            'user_id' => $user->id, 'session' => session()->getId(),
            'login' => session('video.login'), 'origin' => request()->getSchemeAndHttpHost(),
        ]), now()->addMinute());

        return $ticket;
    }

    /**
     * What Jellyfin's web interface needs to resume the session (server id and name, its own user
     * id and token), only after checking the token still belongs to that person's account.
     *
     * @return array{server: string, name: string, user: string, token: string}|null
     */
    public function exchange(string $ticket, string $origin): ?array
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $ticket) === 1, 403);
        $key = 'video-launch:'.hash('sha256', $ticket);
        $value = Cache::get($key);
        abort_unless(is_string($value), 403);
        $pass = Crypt::decrypt($value);
        abort_unless($origin === $pass['origin'] && Cache::add($key.':used', true, 60), 403);
        Cache::forget($key);
        PortalSession::requireActive($pass);
        $user = User::find((int) $pass['user_id']);
        abort_unless($user && $user->hasVerifiedEmail() && self::allowed($user), 403);
        $account = ServiceAccount::where('user_id', $user->id)->where('service', 'video')->firstOrFail();
        $login = $pass['login'] ? Crypt::decrypt($pass['login']) : null;
        if (! is_array($login) || $login['user'] !== $user->id || $login['remote'] !== $account->external_id
            || ! hash_equals($login['password_hash'], hash('sha256', (string) $user->password))) {
            return null;
        }
        try {
            $me = $this->jellyfin->http($login['token'], $login['device'])->get('/Users/Me');
            if (! $me->successful() || ! $this->matches($account, $me->json())) {
                return null;
            }
        } catch (ConnectionException|RuntimeException) {
            return null;
        }

        return ['server' => $login['server'], 'name' => $login['name'], 'user' => $login['remote'], 'token' => $login['token']];
    }

    /** Ends the Jellyfin session the portal opened (sign-out, new sign-in or revocation). */
    public function forget(): void
    {
        $sealed = session()->pull('video.login');
        if (! is_string($sealed)) {
            return;
        }
        try {
            $login = Crypt::decrypt($sealed);
            $this->jellyfin->http($login['token'], $login['device'])->post('/Sessions/Logout');
        } catch (\Throwable) {
            // Best effort: Jellyfin keeps the device listed until its own cleanup.
        }
    }

    private function matches(ServiceAccount $account, mixed $dto): bool
    {
        return is_array($dto)
            && ($dto['Id'] ?? null) === $account->external_id
            && ($dto['Name'] ?? null) === $account->username
            && ($dto['Policy']['IsAdministrator'] ?? true) === false
            && ($dto['Policy']['IsDisabled'] ?? true) === false;
    }
}
