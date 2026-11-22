<?php

namespace App\Services\Files;

use RuntimeException;

/**
 * Keeps a directory equal to a list of hard links (relative path => stored file): creates the
 * missing ones atomically (temporary name + rename) and removes anything else. Used for the
 * personal libraries (MediaLibrary) and the common ones (CommonLibrary). Names come from
 * validated labels through segment(); paths from users never reach it.
 */
class LinkMirror
{
    /**
     * @param  array<string, string>  $desired
     * @return int links in place
     */
    public function apply(string $directory, array $desired): int
    {
        foreach ($desired as $relative => $source) {
            $target = $directory.'/'.$relative;
            $parent = dirname($target);
            if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new RuntimeException('No se puede preparar una subcarpeta del servicio.');
            }
            clearstatcache(true, $target);
            if (! is_link($target) && is_file($target) && fileinode($target) === fileinode($source)) {
                continue;
            }
            // Link next to the target, then rename over it: the service never sees a half-made entry.
            $temporary = $parent.'/.multisuite-'.bin2hex(random_bytes(6));
            if (! link($source, $temporary)) {
                throw new RuntimeException('No se puede enlazar el archivo (¿la carpeta del servicio está en otro disco?).');
            }
            if (! rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('No se puede publicar el archivo en el servicio.');
            }
        }
        $this->prune($directory, $directory, $desired);

        return count($desired);
    }

    /** @param array<string, string> $desired */
    private function prune(string $base, string $directory, array $desired): bool
    {
        $empty = true;
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            $relative = substr($path, strlen($base) + 1);
            if (! is_link($path) && is_dir($path)) {
                if ($this->prune($base, $path, $desired)) {
                    @rmdir($path);
                } else {
                    $empty = false;
                }
            } elseif (! is_link($path) && array_key_exists($relative, $desired)) {
                $empty = false;
            } else {
                @unlink($path);
            }
        }

        return $empty;
    }

    /** A name as one safe path segment (labels never become paths). */
    public static function segment(string $name): string
    {
        $clean = trim(str_replace(['/', '\\', "\0"], '-', $name));

        return $clean === '' || $clean === '.' || $clean === '..' || str_starts_with($clean, '.multisuite-') ? '_'.$clean : $clean;
    }
}
