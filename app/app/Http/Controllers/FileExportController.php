<?php

namespace App\Http\Controllers;

use App\Models\FileExport;
use App\Services\Files\FileExports;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** ZIP downloads of the person's own files; every lookup is scoped to its owner. */
class FileExportController extends Controller
{
    public function __construct(private FileExports $exports) {}

    public function index(Request $request): Response
    {
        $exports = $this->exports->forUser($request->user());

        return response()->view('files.exports', [
            'exports' => $exports,
            'pending' => $exports->contains(fn (FileExport $export) => in_array($export->state, ['queued', 'building'], true)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['selection', 'folder', 'all'])],
            'folder' => ['nullable', 'integer'],
            'files' => ['array', 'max:'.FileExports::MAX_ITEMS],
            'files.*' => ['integer'],
            'folders' => ['array', 'max:500'],
            'folders.*' => ['integer'],
        ]);
        try {
            $export = $this->exports->request(
                $request->user(),
                $data['scope'],
                isset($data['folder']) ? (int) $data['folder'] : null,
                array_values(array_map('intval', $data['files'] ?? [])),
                array_values(array_map('intval', $data['folders'] ?? [])),
            );
        } catch (RecordsNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['export' => $exception->getMessage()]);
        }

        return redirect()->route('files.exports.index')->with('status', $export->state === 'ready'
            ? '«'.$export->name.'» está listo para descargar.'
            : '«'.$export->name.'» se está preparando. Esta página se actualiza sola.');
    }

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

    public function destroy(Request $request, string $exportId): RedirectResponse
    {
        try {
            $this->exports->discard($request->user(), $exportId);
        } catch (RecordsNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['export' => $exception->getMessage()]);
        }

        return redirect()->route('files.exports.index')->with('status', 'Descarga eliminada y espacio liberado.');
    }
}
