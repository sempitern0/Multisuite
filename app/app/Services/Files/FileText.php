<?php

namespace App\Services\Files;

use App\Models\User;
use App\Models\UserFile;
use App\Services\Storage\StoredBlob;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Plain text of a person's own file, a slice at a time, for the assistant (MCP-03, D3).
 * Text formats are read through StoredBlob (decrypting if needed); PDFs are converted by
 * poppler's pdftotext in a separate process with a time limit, from a private temporary copy
 * that is always removed. Nothing is cached or written anywhere else.
 */
class FileText
{
    public const SLICE_BYTES = 32768;

    public const MAX_PDF_BYTES = 26214400;

    private const MAX_PDF_TEXT_BYTES = 4194304;

    private const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json'];

    public function __construct(private PersonalFiles $files) {}

    public static function supports(?string $mime): bool
    {
        return in_array($mime, self::TEXT_MIMES, true) || $mime === 'application/pdf';
    }

    /**
     * @return array{text: string, offset: int, next_offset: int|null, total_bytes: int, format: string}
     */
    public function slice(User $user, UserFile $file, int $offset): array
    {
        // Ownership is part of the lookup: PersonalFiles only opens the person's own uploads.
        $readable = $this->files->readable($user, $file);
        $mime = $readable['mime'];
        if (! self::supports($mime)) {
            throw new RuntimeException('El asistente solo puede leer texto, Markdown, CSV, JSON y PDF.');
        }
        $offset = max(0, $offset);

        if ($mime === 'application/pdf') {
            $text = $this->pdfText($readable['blob'], $readable['size']);
            $total = strlen($text);
            $raw = substr($text, $offset, self::SLICE_BYTES);
        } else {
            $total = $readable['size'];
            $raw = '';
            if ($offset < $total) {
                $readable['blob']->stream($offset, min(self::SLICE_BYTES, $total - $offset), function (string $piece) use (&$raw): void {
                    $raw .= $piece;
                });
            }
        }
        // Cut on a character boundary so the next slice starts cleanly.
        $text = self::withoutPartialCharacter($raw);
        $next = $offset + strlen($text);

        return [
            'text' => mb_scrub($text, 'UTF-8'),
            'offset' => $offset,
            'next_offset' => $next < $total && $text !== '' ? $next : null,
            'total_bytes' => $total,
            'format' => $mime === 'application/pdf' ? 'pdf' : 'text',
        ];
    }

    private function pdfText(StoredBlob $blob, int $size): string
    {
        if ($size > self::MAX_PDF_BYTES) {
            throw new RuntimeException('El PDF es demasiado grande para que el asistente lo lea (máximo 25 MB).');
        }
        $directory = storage_path('app/private/assistant-tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se puede preparar la lectura del PDF.');
        }
        $copy = tempnam($directory, 'pdf');
        if ($copy === false) {
            throw new RuntimeException('No se puede preparar la lectura del PDF.');
        }
        try {
            chmod($copy, 0600);
            $handle = fopen($copy, 'wb');
            if ($handle === false) {
                throw new RuntimeException('No se puede preparar la lectura del PDF.');
            }
            try {
                $blob->stream(0, $size, function (string $piece) use ($handle): void {
                    fwrite($handle, $piece);
                });
            } finally {
                fclose($handle);
            }
            // Fixed binary and arguments, no shell; first 300 pages at most.
            try {
                $result = Process::timeout(30)->run(['pdftotext', '-q', '-enc', 'UTF-8', '-l', '300', $copy, '-']);
            } catch (Throwable) {
                throw new RuntimeException('No se ha podido sacar el texto de este PDF a tiempo. Avisa a quien administra Multisuite si pasa con todos.');
            }
        } finally {
            @unlink($copy);
        }
        if (! $result->successful()) {
            throw new RuntimeException($result->exitCode() === 127
                ? 'Este servidor todavía no puede leer PDF (falta pdftotext). Avisa a quien administra Multisuite.'
                : 'No se ha podido sacar el texto de este PDF (puede estar protegido o ser solo imágenes).');
        }

        return substr($result->output(), 0, self::MAX_PDF_TEXT_BYTES);
    }

    /** Drops an incomplete UTF-8 character at the end of a byte slice. */
    private static function withoutPartialCharacter(string $raw): string
    {
        $length = strlen($raw);
        for ($back = 1; $back <= min(4, $length); $back++) {
            $byte = ord($raw[$length - $back]);
            if (($byte & 0xC0) === 0x80) {
                continue;
            }
            $size = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $size > $back ? substr($raw, 0, $length - $back) : $raw;
        }

        return $raw;
    }
}
