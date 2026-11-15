<?php

namespace App\Services\Files;

use App\Enums\FileKind;
use App\Models\User;
use App\Models\UserFile;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Read-only previews: images (zoomable viewer), video and audio played by the
 * browser as stored, PDF (browser viewer), sanitised Markdown and plain text. Everything else
 * is download-only (AGENTS.md: no editors or transcoding).
 */
class FilePreview
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    private const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json'];

    public function __construct(private readonly PersonalFiles $files) {}

    /** @return 'image'|'video'|'audio'|'pdf'|'markdown'|'text'|'none' */
    public static function kind(string $mime, string $name): string
    {
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            in_array($mime, self::IMAGE_MIMES, true) => 'image',
            in_array($mime, self::VIDEO_MIMES, true) => 'video',
            FileKind::fromMime($mime) === FileKind::Audio => 'audio',
            $mime === 'application/pdf' => 'pdf',
            $mime === 'text/markdown', $mime === 'text/plain' && in_array($extension, ['md', 'markdown'], true) => 'markdown',
            in_array($mime, self::TEXT_MIMES, true) => 'text',
            default => 'none',
        };
    }

    /**
     * @return array{kind: string, mime: string, size: int, text: ?string, html: ?string, truncated: bool, error: ?string}
     */
    public function build(User $user, UserFile $file): array
    {
        $preview = ['kind' => 'none', 'mime' => '', 'size' => 0, 'text' => null, 'html' => null, 'truncated' => false, 'error' => null];
        try {
            $readable = $this->files->readable($user, $file);
            $preview['mime'] = (string) $readable['mime'];
            $preview['size'] = (int) $readable['size'];
            $preview['kind'] = self::kind($preview['mime'], $file->name);
            if (in_array($preview['kind'], ['text', 'markdown'], true)) {
                $max = (int) config('nas.preview_text_max_bytes');
                $raw = (string) file_get_contents($readable['path'], false, null, 0, max(1, $max + 1));
                $preview['truncated'] = strlen($raw) > $max;
                $preview['text'] = mb_scrub(substr($raw, 0, $max), 'UTF-8');
                if ($preview['kind'] === 'markdown' && ! $preview['truncated']) {
                    // Raw HTML stripped and unsafe link schemes refused: output is safe to print.
                    $preview['html'] = Str::markdown($preview['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
                }
            }
        } catch (RecordsNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $preview['error'] = $exception->getMessage();
        }

        return $preview;
    }
}
