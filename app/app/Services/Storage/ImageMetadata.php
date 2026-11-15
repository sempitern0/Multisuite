<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Removes private metadata from uploaded pictures with ExifTool (EXIF, GPS location, camera
 * and serial numbers, XMP, IPTC, comments, embedded thumbnails). Only the orientation (so the
 * picture is not shown sideways) and the colour profile (so colours do not change) are kept.
 * The pixels are never touched. Fails closed: if the metadata cannot be removed, the upload
 * is refused rather than stored with it.
 */
class ImageMetadata
{
    private const TIMEOUT_SECONDS = 60;

    /** Formats ExifTool can rewrite in place, with the extension it needs to recognise them. */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];

    /** Whether files of this type are cleaned (and so must be kept whole until the last chunk). */
    public function wants(string $mime): bool
    {
        return config('nas.strip_image_metadata') && isset(self::EXTENSIONS[$mime]);
    }

    /** @throws UnsafeImageMetadata when the picture cannot be cleaned */
    public function strip(string $path, string $mime): void
    {
        if (! $this->wants($mime)) {
            return;
        }
        $work = $path.'.meta.'.self::EXTENSIONS[$mime];
        try {
            if (file_exists($work) || is_link($work) || ! copy($path, $work) || ! chmod($work, 0600)) {
                throw new UnsafeImageMetadata;
            }
            // Delete everything except the colour profile, then copy the orientation back from the original.
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
                'exiftool', '-q', '-q', '-m', '-overwrite_original',
                '-all=', '--ICC_Profile:all', '-tagsFromFile', '@', '-Orientation',
                $work,
            ]);
            clearstatcache();
            if (! $result->successful() || ! $this->samePicture($path, $work, self::TYPES[$mime]) || ! rename($work, $path)) {
                throw new UnsafeImageMetadata;
            }
        } catch (UnsafeImageMetadata $exception) {
            throw $exception;
        } catch (Throwable) {
            // Missing tool, timeout, unreadable picture.
            throw new UnsafeImageMetadata;
        } finally {
            if (is_file($work)) {
                @unlink($work);
            }
        }
    }

    private function samePicture(string $original, string $candidate, int $type): bool
    {
        if (is_link($candidate) || ! is_file($candidate) || filesize($candidate) === 0) {
            return false;
        }
        $before = @getimagesize($original);
        $after = @getimagesize($candidate);

        return $before !== false && $after !== false && $after[2] === $type
            && $after[0] === $before[0] && $after[1] === $before[1];
    }
}
