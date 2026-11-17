<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class MusicSessionBridge
{
    public function issue(ServiceAccount $account, string $destination, ?string $challenge = null): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 2,
            'challenge' => $challenge,
            'launcher' => route('services.open', 'music'),
            'session' => config('portal.music_autologin') ? session()->getId() : null,
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'destination' => $destination,
            'expires' => now()->addMinute()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param-out string|null $grant
     * @param-out string|null $newNonce
     *
     * @return array{id: string, username: string, destination: string}|array{confirm: string}
     */
    public function verify(string $ticket, ?string &$grant = null, ?string $browserNonce = null, ?string &$newNonce = null): array
    {
        $grant = null;
        $newNonce = null;
        abort_unless(config('portal.music_session_bridge')
            && config('portal.services.music.enabled')
            && config('provisioning.music.enabled'), 403);

        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(403);
        }
        abort_unless(is_array($payload)
            && ($payload['version'] ?? null) === 2
            && is_int($payload['account_id'] ?? null)
            && is_int($payload['user_id'] ?? null)
            && is_int($payload['expires'] ?? null)
            && $payload['expires'] > now()->timestamp
            && $payload['expires'] <= now()->addMinute()->timestamp
            && is_string($payload['destination'] ?? null)
            && $payload['destination'] === config('portal.services.music.url'), 403);

        $account = $this->readyAccount($payload);
        if (config('portal.music_autologin')) {
            PortalSession::requireActive($payload);
            abort_unless(Cache::add('music-ticket:'.hash('sha256', $ticket), true, 60), 403);
            if ($payload['challenge'] === null) {
                $newNonce = bin2hex(random_bytes(32));

                return ['confirm' => $payload['launcher'].'?music_challenge='.hash('sha256', $newNonce)];
            }
            abort_unless(is_string($browserNonce) && strlen($browserNonce) === 64
                && hash_equals($payload['challenge'], hash('sha256', $browserNonce)), 403);
            app(NavidromeAccounts::class)->verifyForLogin($account);
            $grant = bin2hex(random_bytes(32));
            Cache::put('music-grant:'.hash('sha256', $grant), [
                ...$payload,
                'remote_id' => $account->remote_id,
                'username' => $account->username,
            ], now()->addMinutes((int) config('session.lifetime')));
        }

        return ['id' => $account->remote_id, 'username' => $account->username, 'destination' => $payload['destination']];
    }

    public function authorize(string $grant, ?string $origin): string
    {
        abort_unless(config('portal.music_autologin') && config('portal.music_session_bridge')
            && config('portal.services.music.enabled') && config('provisioning.music.enabled')
            && preg_match('/^[a-f0-9]{64}$/D', $grant), 403);
        $payload = Cache::get('music-grant:'.hash('sha256', $grant));
        abort_unless(is_array($payload) && $payload['destination'] === config('portal.services.music.url'), 403);
        if ($origin !== null && $origin !== '') {
            $parts = parse_url($payload['destination']);
            abort_unless(is_array($parts) && isset($parts['host'], $parts['scheme']), 403);
            $expected = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            abort_unless($origin === $expected, 403);
        }
        PortalSession::requireActive($payload);
        $account = $this->readyAccount($payload);
        abort_unless($account->remote_id === $payload['remote_id'] && $account->username === $payload['username'], 403);

        return $account->username;
    }

    /** @param array<string, mixed> $payload */
    private function readyAccount(array $payload): ServiceAccount
    {
        $account = ServiceAccount::whereKey($payload['account_id'])
            ->where('user_id', $payload['user_id'])->where('service', 'music')->where('status', 'ready')->first();
        $user = User::find((int) $payload['user_id']);
        abort_unless($account && $user && ! $user->isSuspended() && $user->hasVerifiedEmail() && ! $user->must_change_password
            && in_array('music', $user->service_access ?? [], true), 403);

        return $account;
    }
}
