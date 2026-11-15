<?php

namespace App\Http\Controllers;

use App\Http\Responses\BlobResponse;
use App\Models\FileExport;
use App\Services\Files\FileExports;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** ZIP downloads of the person's own files; every lookup is scoped to its owner. */
class FileExportController extends Controller
{
    public function __construct(private FileExports $exports) {}

    public function download(Request $request, string $exportId): Response
    {
        try {
            $blob = $this->exports->download($request->user(), $exportId);
        } catch (RecordsNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException) {
            abort(410, 'Esta descarga ya no está disponible.');
        }
        $export = FileExport::where('user_id', $request->user()->id)->findOrFail($exportId);

        return BlobResponse::make($request, $blob, $export->name, 'attachment', [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
