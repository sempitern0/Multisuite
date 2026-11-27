<?php

namespace App\Services\Files;

use App\Services\Provisioning\ScansMediaChanges;
use App\Services\Provisioning\ServiceProvisioners;
use Illuminate\Support\Facades\Cache;

/**
 * Asks a service to rescan only when a mirrored directory really changed. The mirror's content
 * (relative names → immutable uploads) is hashed per directory; a scan is recorded only after it
 * succeeds, so a failed one is retried on the next reconciliation.
 */
final class MediaScans
{
    public function __construct(private ServiceProvisioners $provisioners) {}

    /** @param array<string, string> $files relative library names mapped to immutable uploads */
    public function changed(string $service, string $directory, array $files): void
    {
        if (! config('provisioning.'.$service.'.enabled') || ! $this->provisioners->ready($service)) {
            return;
        }
        $adapter = $this->provisioners->for($service);
        if (! $adapter instanceof ScansMediaChanges) {
            return;
        }
        ksort($files);
        $revision = hash('sha256', serialize($files));
        $key = 'media-scan:'.$service.':'.hash('sha256', $directory);
        if (Cache::get($key) !== $revision) {
            $adapter->scanMedia();
            Cache::put($key, $revision, now()->addDay());
        }
    }
}
