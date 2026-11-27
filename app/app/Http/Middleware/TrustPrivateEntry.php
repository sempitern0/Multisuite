<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Behind the private entry (docs/VPN.md) the scheme and host come from X-Forwarded-*.
 * Only proxies in TRUSTED_PROXIES (config app.trusted_proxies) are believed; empty means none.
 * Read on every request, so configuration changes and tests need no static state.
 */
class TrustPrivateEntry extends TrustProxies
{
    /** @return array<int, string>|null */
    protected function proxies()
    {
        /** @var list<string> $proxies */
        $proxies = config('app.trusted_proxies', []);

        return $proxies === [] ? null : $proxies;
    }
}
