<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

final class KomgaSession
{
    public static function allowed(User $user): bool
    {
        return (bool) config('portal.services.komga.enabled')
            && config('provisioning.komga.enabled')
            && app(ServiceAccounts::class)->entitled($user, 'komga')
            && ! $user->must_change_password
            && ServiceAccount::where('user_id', $user->id)->where('service', 'komga')->where('status', 'ready')->exists();
    }

    public function prepare(User $user, #[\SensitiveParameter] string $password): void
    {
        session()->forget('komga.login');
        if (! config('portal.services.komga.enabled') || ! config('provisioning.komga.enabled')
            || $user->isSuspended() || $user->must_change_password) {
            return;
        }
        try {
            if (! KomgaAccounts::connected()) {
                if ($user->role !== 'owner' || ! $user->hasVerifiedEmail() || ! app(ServiceAccounts::class)->entitled($user, 'komga')) {
                    return;
                }
                // Fresh installs and an existing administrator with the same credentials need no setup form.
                app(KomgaAccounts::class)->connect($user, $user->email, $password);
            }
            app(ServiceAccounts::class)->resume($user, $password);
            $user->refresh();
            $account = ServiceAccount::where('user_id', $user->id)->where('service', 'komga')->first();
            if ($account === null || ! app(ServiceAccounts::class)->entitled($user, 'komga')) {
                return;
            }
            app(ServiceAccounts::class)->synchronize($account->id);
            $account->refresh();
            if (! self::allowed($user)) {
                return;
            }
            // Komga identifies accounts by email, even when Multisuite accepts a username.
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(4)
                ->withBasicAuth((string) $account->remote_email, $password)->get($this->url().'/api/v2/users/me');
            if (! $response->successful() || ! $this->matches($user, $response->json())) {
                return;
            }
            $cookie = $this->sessionCookie($response->toPsrResponse()->getHeader('Set-Cookie'));
            if ($cookie === null) {
                return;
            }
            session()->put('komga.login', Crypt::encrypt([
                'user' => $user->id, 'password_hash' => hash('sha256', (string) $user->password),
                'cookie' => $cookie,
            ]));
        } catch (ConnectionException|RuntimeException) {
            // A service outage must never prevent signing in to Multisuite.
        }
    }

    public function issue(User $user): string
    {
        abort_unless(self::allowed($user), 403);
        $ticket = Str::random(64);
        Cache::put('komga-launch:'.hash('sha256', $ticket), Crypt::encrypt([
            'user_id' => $user->id, 'session' => session()->getId(),
            'login' => session('komga.login'), 'origin' => request()->getSchemeAndHttpHost(),
        ]), now()->addMinute());

        return $ticket;
    }

    /** Returns only a verified native session cookie, never a password. */
    public function exchange(string $ticket, string $origin): ?string
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $ticket) === 1, 403);
        $key = 'komga-launch:'.hash('sha256', $ticket);
        $value = Cache::get($key);
        abort_unless(is_string($value), 403);
        $pass = Crypt::decrypt($value);
        abort_unless($origin === $pass['origin'] && Cache::add($key.':used', true, 60), 403);
        Cache::forget($key);
        PortalSession::requireActive($pass);
        $user = User::find((int) $pass['user_id']);
        abort_unless($user && $user->hasVerifiedEmail() && self::allowed($user), 403);
        $login = $pass['login'] ? Crypt::decrypt($pass['login']) : null;
        if (! $login || $login['user'] !== $user->id
            || ! hash_equals($login['password_hash'], hash('sha256', (string) $user->password))) {
            return null;
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(4)
                ->withHeaders(['Cookie' => 'KOMGA-SESSION='.$login['cookie']])
                ->get($this->url().'/api/v2/users/me');
            if ($response->successful() && $this->matches($user, $response->json())) {
                return $this->sessionCookie($response->toPsrResponse()->getHeader('Set-Cookie')) ?? $login['cookie'];
            }
        } catch (ConnectionException|RuntimeException) {
            // The native login remains available when the hand-off cannot be verified.
        }

        return null;
    }

    private function url(): string
    {
        return ServiceUrl::validated((string) config('portal.komga_internal_url'));
    }

    private function matches(User $user, mixed $dto): bool
    {
        $account = ServiceAccount::where('user_id', $user->id)->where('service', 'komga')->first();

        return $account !== null && is_array($dto)
            && ($dto['id'] ?? null) === $account->external_id
            && ($dto['email'] ?? null) === $account->remote_email
            && is_array($dto['roles'] ?? null)
            && ($user->role === 'owner' || ! in_array('ADMIN', $dto['roles'], true));
    }

    /** @param array<string> $headers */
    private function sessionCookie(array $headers): ?string
    {
        foreach ($headers as $header) {
            $cookie = Cookie::fromString($header);
            $value = $cookie->getValue();
            if ($cookie->getName() === 'KOMGA-SESSION' && is_string($value)
                && preg_match('/^[a-zA-Z0-9_+=\/-]{16,512}$/D', $value)) {
                return $value;
            }
        }

        return null;
    }
}
