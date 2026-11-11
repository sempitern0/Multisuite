<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class MusicSessionBridge
{
    public function issue(ServiceAccount $account, string $destination): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'destination' => $destination,
            'expires' => now()->addMinute()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{id: string, username: string, destination: string} */
    public function verify(string $ticket): array
    {
        abort_unless(config('portal.music_session_bridge')
            && config('portal.services.music.enabled')
            && config('provisioning.music.enabled'), 403);

        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(403);
        }
        abort_unless(is_array($payload)
            && ($payload['version'] ?? null) === 1
            && is_int($payload['account_id'] ?? null)
            && is_int($payload['user_id'] ?? null)
            && is_int($payload['expires'] ?? null)
            && $payload['expires'] > now()->timestamp
            && $payload['expires'] <= now()->addMinute()->timestamp
            && is_string($payload['destination'] ?? null)
            && $payload['destination'] === config('portal.services.music.url'), 403);

        $account = ServiceAccount::whereKey($payload['account_id'])
            ->where('user_id', $payload['user_id'])->where('service', 'music')->where('status', 'ready')->first();
        $user = User::find($payload['user_id']);
        abort_unless($account && $user && $user->hasVerifiedEmail() && ! $user->must_change_password
            && in_array('music', $user->service_access ?? [], true), 403);

        return ['id' => $account->remote_id, 'username' => $account->username, 'destination' => $payload['destination']];
    }
}
