<?php

namespace App\Services\Downloads;

use App\Models\User;
use App\Services\PortalSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Who may open Descargas (MeTube, docs/DOWNLOADS.md) and how its gateway knows it.
 * MeTube has no accounts: «Descargas» in the portal hands a one-minute single-use ticket to the
 * gateway's origin, the portal answers it with an encrypted session cookie for that origin, and the
 * gateway asks the portal on every request whether that cookie still belongs to an active owner or
 * administrator. Changing the password or the role ends it at once; otherwise it lasts 8 hours.
 */
final class DownloadsAccess
{
    public const COOKIE = 'multisuite_downloads';

    public const SESSION_MINUTES = 480;

    private const TICKET_SECONDS = 60;

    public static function enabled(): bool
    {
        return (bool) config('portal.services.downloads.enabled');
    }

    public static function allowed(?User $user): bool
    {
        return self::enabled() && $user !== null && ! $user->isSuspended() && ! $user->must_change_password && $user->isAdministrator();
    }

    public function issue(User $user): string
    {
        abort_unless(self::allowed($user), 403);
        $ticket = Str::random(64);
        // Bound to this portal session and to the portal page that submits it (as Komga and Vídeo).
        Cache::put(self::ticketKey($ticket), Crypt::encrypt([
            'user_id' => $user->id, 'session' => session()->getId(), 'origin' => request()->getSchemeAndHttpHost(),
        ]), self::TICKET_SECONDS);

        return $ticket;
    }

    /**
     * The gateway's session for a ticket, or null (unknown, used, expired, submitted from another
     * page or no longer allowed). URL-safe so it travels as a raw cookie.
     */
    public function redeem(string $ticket, string $origin): ?string
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/D', $ticket) !== 1) {
            return null;
        }
        $value = Cache::pull(self::ticketKey($ticket)); // Single use, even when refused below.
        $pass = is_string($value) ? Crypt::decrypt($value) : null;
        if (! is_array($pass) || ! is_string($pass['origin'] ?? null) || ! hash_equals($pass['origin'], $origin)) {
            return null;
        }
        try {
            PortalSession::requireActive($pass); // The portal session that issued it must still be open.
        } catch (HttpException) {
            return null; // Like every other refusal: back to the portal, not an error page on the gateway.
        }
        $user = User::find((int) ($pass['user_id'] ?? 0));
        if ($user === null || ! self::allowed($user)) {
            return null;
        }
        $payload = json_encode(['u' => $user->id, 'f' => self::fingerprint($user), 'e' => now()->addMinutes(self::SESSION_MINUTES)->getTimestamp()], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode(Crypt::encryptString($payload)), '+/', '-_'), '=');
    }

    /** The person a gateway session belongs to, while it is valid and still allowed. */
    public function holder(string $session): ?User
    {
        if ($session === '' || strlen($session) > 2048 || preg_match('/^[A-Za-z0-9_-]+$/D', $session) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($session, '-_', '+/'), true);
        try {
            $data = $raw === false ? null : json_decode(Crypt::decryptString($raw), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($data) || ! is_int($data['u'] ?? null) || ! is_string($data['f'] ?? null) || ! is_int($data['e'] ?? null) || $data['e'] < now()->getTimestamp()) {
            return null;
        }
        $user = User::find($data['u']);

        return $user !== null && self::allowed($user) && hash_equals(self::fingerprint($user), $data['f']) ? $user : null;
    }

    private static function ticketKey(string $ticket): string
    {
        return 'downloads-launch:'.hash('sha256', $ticket);
    }

    /** Changes when the password or the role changes, ending every gateway session. */
    private static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->role.'|'.$user->password, (string) config('app.key'));
    }
}
