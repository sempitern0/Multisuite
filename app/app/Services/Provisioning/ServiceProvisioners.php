<?php

namespace App\Services\Provisioning;

use App\Services\JellyfinAccounts;
use App\Services\KavitaAccounts;
use App\Services\KomgaAccounts;
use App\Services\NavidromeAccounts;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Registry of adapters by service identifier: the only place that knows which class serves which service. */
class ServiceProvisioners
{
    /** @var array<string, class-string<ServiceProvisioner>> */
    private const ADAPTERS = [
        'music' => NavidromeAccounts::class,
        'reading' => KavitaAccounts::class,
        'komga' => KomgaAccounts::class,
        'video' => JellyfinAccounts::class,
    ];

    public function supports(string $service): bool
    {
        return array_key_exists($service, self::ADAPTERS);
    }

    /** Supported and, when it needs the owner's one-off connection, already connected. */
    public function ready(string $service): bool
    {
        if (! $this->supports($service)) {
            return false;
        }
        $adapter = self::ADAPTERS[$service];

        return ! is_subclass_of($adapter, NeedsConnection::class) || $adapter::connected();
    }

    public function for(string $service): ServiceProvisioner
    {
        if (! $this->supports($service)) {
            throw new RuntimeException('configuration');
        }

        return app(self::ADAPTERS[$service]);
    }

    /** @param array<string, string> $files relative library names mapped to immutable uploads */
    public function notifyMediaChanges(string $service, string $directory, array $files): void
    {
        if (! config('provisioning.'.$service.'.enabled') || ! $this->ready($service)) {
            return;
        }
        $adapter = $this->for($service);
        if (! $adapter instanceof ScansMediaChanges) {
            return;
        }
        ksort($files);
        $revision = hash('sha256', serialize($files));
        $key = 'media-scan:'.$service.':'.hash('sha256', $directory);
        $previous = Cache::get($key);
        if ($previous !== $revision) {
            $adapter->scanMedia();
            Cache::put($key, $revision, now()->addDay());
        }
    }
}
