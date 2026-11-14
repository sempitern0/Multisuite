<?php

namespace App\Http\Controllers;

use App\Models\FileExport;
use App\Services\Files\FileExports;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** ZIP downloads of the person's own files; every lookup is scoped to its owner. */
class FileExportController extends Controller
{
    public function __construct(private FileExports $exports) {}

    public function download(Request $request, string $exportId): BinaryFileResponse
    {
        try {
            $path = $this->exports->downloadPath($request->user(), $exportId);
        } catch (RecordsNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException) {
            abort(410, 'Esta descarga ya no está disponible.');
        }
        $export = FileExport::where('user_id', $request->user()->id)->findOrFail($exportId);
        $response = response()->file($path, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
        $fallback = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($export->name));
        $response->setContentDisposition('attachment', $export->name, trim($fallback, ' .') !== '' ? $fallback : 'archivos.zip');

        return $response;
    }
}
