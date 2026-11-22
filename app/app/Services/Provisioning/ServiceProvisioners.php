<?php

namespace App\Services\Provisioning;

use App\Services\JellyfinAccounts;
use App\Services\KavitaAccounts;
use App\Services\KomgaAccounts;
use App\Services\NavidromeAccounts;
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
}
