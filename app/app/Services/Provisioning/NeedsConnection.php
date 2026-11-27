<?php

namespace App\Services\Provisioning;

/**
 * A service the owner must connect once from the portal (an API key it keeps) before any account
 * can exist in it. Until then ServiceAccounts treats it as unavailable.
 */
interface NeedsConnection
{
    public static function connected(): bool;
}
