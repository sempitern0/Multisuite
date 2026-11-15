<?php

namespace App\Services\Provisioning;

use RuntimeException;

/**
 * Internal address of a provisioned service, taken only from operator configuration. No
 * credentials, query or fragment; plain HTTP is refused in production.
 */
final class ServiceUrl
{
    public static function validated(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || (app()->isProduction() && $parts['scheme'] !== 'https')) {
            throw new RuntimeException('configuration');
        }

        return $url;
    }
}
