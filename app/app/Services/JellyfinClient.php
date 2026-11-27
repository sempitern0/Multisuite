<?php

namespace App\Services;

use App\Models\ServiceConnection;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transport to Jellyfin (docs/JELLYFIN.md): its MediaBrowser Authorization header, the API key the
 * owner's connection stored, and one mapping of HTTP failures to the portal's error codes. Shared by
 * JellyfinServer (the server), JellyfinAccounts (people's accounts) and VideoSession (browsers).
 */
final class JellyfinClient
{
    /** Device of the portal's own calls. */
    public const DEVICE = 'multisuite-portal';

    /** Devices the portal opens for people's browsers (VideoSession); never the portal's own. */
    public const DEVICE_PREFIX = 'multisuite-web-';

    public static function connected(): bool
    {
        return ServiceConnection::whereKey('video')->exists();
    }

    /** One Authorization header, with a token when given; $device names a person's browser session. */
    public function http(#[\SensitiveParameter] ?string $token = null, string $device = self::DEVICE): PendingRequest
    {
        $client = 'MediaBrowser Client="Multisuite", Device="Multisuite", DeviceId="'.preg_replace('/[^a-z0-9-]/', '', $device).'", Version="1.0.0"';

        return Http::baseUrl(ServiceUrl::validated((string) config('portal.video_internal_url')))
            ->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(2)->timeout(10)
            ->replaceHeaders(['Authorization' => $client.($token === null ? '' : ', Token="'.$token.'"')]);
    }

    /** Authenticated with the API key of the owner's connection. */
    public function admin(): PendingRequest
    {
        $connection = ServiceConnection::find('video') ?? throw new RuntimeException('configuration');

        return $this->http($connection->secret);
    }

    /** Failures of the connection steps, mapped to what the owner is told (wizard, sign-in, key). */
    public function stage(Response $response, string $stage): Response
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

    /** A rejected API key is a configuration problem; anything else unsuccessful, unavailability. */
    public function check(Response $response): Response
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('configuration');
        }
        if (! $response->successful()) {
            throw new RuntimeException('unavailable');
        }

        return $response;
    }

    /** Runs $operation with network failures reported as 'unavailable'. */
    public function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }
}
