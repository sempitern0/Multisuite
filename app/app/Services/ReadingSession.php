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

class ReadingSession
{
    public function prepare(User $user, #[\SensitiveParameter] string $password): void
    {
        session()->forget('reading.login');
        if (! config('portal.reading_autologin') || ! config('provisioning.reading.enabled')
            || ! in_array('reading', $user->service_access ?? [], true)) {
            return;
        }
        $account = $this->account($user);
        if (! $account) {
            return;
        }
        try {
            $url = ServiceUrl::validated((string) config('provisioning.reading.url'));
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(4)
                ->post($url.'/api/Account/login', ['username' => $account->username, 'password' => $password]);
        } catch (ConnectionException|\RuntimeException) {
            return;
        }
        $dto = $response->json();
        if (! $response->successful() || ! is_array($dto) || ($dto['email'] ?? '') !== KavitaAccounts::identity($account)
            || ($dto['username'] ?? '') !== $account->username || ! is_array($dto['roles'] ?? null)
            || in_array('Admin', $dto['roles'], true) || ! in_array('Login', $dto['roles'], true)
            || empty($dto['token']) || empty($dto['refreshToken'])) {
            return;
        }
        unset($dto['authKeys'], $dto['apiKey']);
        session()->put('reading.login', Crypt::encrypt(['user' => $user->id, 'dto' => $dto]));
    }

    public function issue(User $user): string
    {
        $ticket = Str::random(64);
        Cache::put('reading-launch:'.hash('sha256', $ticket), Crypt::encrypt([
            'user' => $user->id, 'login' => session('reading.login'), 'origin' => request()->getSchemeAndHttpHost(),
        ]), now()->addMinute());

        return $ticket;
    }

    /** @return array<string, mixed>|null */
    public function exchange(string $ticket, string $origin): ?array
    {
        abort_unless(config('portal.reading_autologin') && config('portal.services.reading.enabled')
            && config('provisioning.reading.enabled') && preg_match('/^[a-zA-Z0-9]{64}$/D', $ticket), 403);
        $key = 'reading-launch:'.hash('sha256', $ticket);
        $value = Cache::get($key);
        abort_unless(is_string($value), 403);
        $pass = Crypt::decrypt($value);
        abort_unless($origin === $pass['origin'] && Cache::add($key.':used', true, 60), 403);
        Cache::forget($key);
        $user = User::find((int) $pass['user']);
        abort_unless($user && $user->hasVerifiedEmail() && ! $user->isSuspended() && ! $user->must_change_password
            && in_array('reading', $user->service_access ?? [], true), 403);
        $account = $this->account($user);
        abort_unless($account, 403);
        $login = $pass['login'] ? Crypt::decrypt($pass['login']) : null;
        if (! $login || $login['user'] !== $user->id || $login['dto']['email'] !== KavitaAccounts::identity($account)
            || $login['dto']['username'] !== $account->username) {
            return null;
        }

        return $login['dto'];
    }

    private function account(User $user): ?ServiceAccount
    {
        return ServiceAccount::where('user_id', $user->id)->where('service', 'reading')->where('status', 'ready')->first();
    }
}
