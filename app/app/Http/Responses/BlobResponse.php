<?php

namespace App\Http\Responses;

use App\Services\Storage\StoredBlob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file (decrypting it when needed) with single byte ranges, so the
 * browser's video/audio players can seek and downloads can resume. Multiple ranges are
 * answered with the whole file, as RFC 9110 allows.
 */
final class BlobResponse
{
    /** @param  array<string, string>  $headers */
    public static function make(Request $request, StoredBlob $blob, string $filename, string $disposition, array $headers): Response
    {
        $size = $blob->size();
        [$start, $end] = [0, $size - 1];
        $status = 200;
        $range = (string) $request->headers->get('Range', '');
        if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) === 1 && ($match[1] !== '' || $match[2] !== '')) {
            if ($match[1] === '') {
                $start = max(0, $size - (int) $match[2]);
            } else {
                $start = (int) $match[1];
                $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
            }
            if ($start > $end || $start >= $size) {
                return new Response('', 416, ['Content-Range' => 'bytes */'.$size] + $headers);
            }
            $status = 206;
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }
        $length = max(0, $end - $start + 1);
        $fallback = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($filename));
        $headers += [
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, trim($fallback, ' .') !== '' ? $fallback : 'archivo'),
        ];

        return new StreamedResponse(function () use ($blob, $start, $length): void {
            if ($length > 0) {
                $blob->stream($start, $length, function (string $piece): void {
                    echo $piece;
                    flush();
                });
            }
        }, $status, $headers);
    }
}
