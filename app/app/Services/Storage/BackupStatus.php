<?php

namespace App\Services\Storage;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Read-only result of the last `make nas-backup`, written on the server and mounted
 * into the container. The portal never runs or schedules backups itself; a missing
 * or malformed file means no backup is known.
 */
class BackupStatus
{
    private const MAX_BYTES = 4096;

    /**
     * @return array{state: 'ok'|'stale'|'failed'|'running', at: ?CarbonImmutable}|null
     */
    public function read(): ?array
    {
        $path = (string) config('nas.backup_status', '');
        if ($path === '' || ! is_file($path) || is_link($path) || filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true, 8);
        if (! is_array($data) || ! array_key_exists('ok', $data)) {
            return null;
        }
        $finished = $this->date($data['finished_at'] ?? null);
        if ($data['ok'] === null) {
            return ['state' => 'running', 'at' => $this->date($data['started_at'] ?? null)];
        }
        if ($data['ok'] !== true || $finished === null) {
            return ['state' => 'failed', 'at' => $finished];
        }
        $stale = $finished->lt(CarbonImmutable::now()->subHours((int) config('nas.backup_max_age_hours')));

        return ['state' => $stale ? 'stale' : 'ok', 'at' => $finished];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
