<?php

namespace App\Livewire\Concerns;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Livewire actions reach /livewire/update and skip the route's `throttle:` middleware.
 * Mutating actions call throttle() with the same named limiter as their route
 * (AppServiceProvider) so both paths share one budget and one definition.
 */
trait ThrottlesActions
{
    protected function throttle(string $limiterName): void
    {
        $limiter = RateLimiter::limiter($limiterName);
        abort_if($limiter === null, 500, 'Unknown rate limiter.');

        /** @var Limit|list<Limit> $limits */
        $limits = $limiter(request());
        foreach (is_array($limits) ? $limits : [$limits] as $limit) {
            // Same key derivation as Illuminate\Routing\Middleware\ThrottleRequests (hashed keys).
            $key = md5($limiterName.$limit->key);
            abort_if(RateLimiter::tooManyAttempts($key, $limit->maxAttempts), 429);
            RateLimiter::hit($key, $limit->decaySeconds);
        }
    }
}
