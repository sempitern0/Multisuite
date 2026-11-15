<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Shrinks a received file without changing what it shows: JPEG entropy coding is
 * rebuilt by jpegtran (same pixels, metadata kept). Anything else, and any doubt
 * about the result, leaves the file exactly as received. Never re-encodes.
 */
class LosslessOptimizer
{
    private const TIMEOUT_SECONDS = 20;

    /**
     * Whether a file of this type and size gets optimised (and so must be kept whole until
     * the last chunk). Large files are left as received to keep the final request short.
     */
    public function wants(string $mime, int $bytes): bool
    {
        return config('nas.optimize_images') && $mime === 'image/jpeg' && $bytes <= (int) config('nas.optimize_max_bytes');
    }

    /** @return bool true when the file was replaced by a smaller identical picture */
    public function optimize(string $path, string $mime): bool
    {
        if (! $this->wants($mime, (int) filesize($path))) {
            return false;
        }
        $candidate = $path.'.opt';
        $replaced = false;
        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)
                ->run(['jpegtran', '-copy', 'all', '-optimize', '-progressive', '-outfile', $candidate, $path]);
            clearstatcache();
            if ($result->successful() && $this->sameImageAndSmaller($path, $candidate) && chmod($candidate, 0600) && $this->persisted($candidate)) {
                $replaced = rename($candidate, $path);
            }
        } catch (Throwable) {
            // Missing tool, timeout or unreadable image: keep the original.
        } finally {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        return $replaced;
    }

    private function sameImageAndSmaller(string $original, string $candidate): bool
    {
        if (is_link($candidate) || ! is_file($candidate)) {
            return false;
        }
        $before = @getimagesize($original);
        $after = @getimagesize($candidate);

        return $before !== false && $after !== false
            && $after[2] === IMAGETYPE_JPEG
            && $after[0] === $before[0] && $after[1] === $before[1]
            && filesize($candidate) > 0 && filesize($candidate) < filesize($original);
    }

    private function persisted(string $path): bool
    {
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            return false;
        }
        try {
            return fsync($handle);
        } finally {
            fclose($handle);
        }
    }
}
