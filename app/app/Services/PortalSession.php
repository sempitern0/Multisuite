<?php

namespace App\Services;

use Illuminate\Auth\SessionGuard;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

final class PortalSession
{
    /** @param array<string, mixed> $payload */
    public static function requireActive(array $payload): void
    {
        abort_unless(is_string($payload['session'] ?? null) && config('session.driver') !== 'cookie', 403);
        // A fresh store reads the original server-side session without touching its lifetime.
        /** @var Store $store */
        $store = app('session')->driver();
        $session = clone $store;
        $session->flush();
        $session->setId($payload['session']);
        $session->start();
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        abort_unless((string) $session->get($guard->getName()) === (string) $payload['user_id'], 403);
    }
}
