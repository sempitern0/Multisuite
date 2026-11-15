<?php

namespace App\Http\Controllers;

use App\Services\Files\FilePreview;
use App\Services\Files\PersonalFiles;
use App\Support\DomainErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Non-page endpoints of Mis archivos: upload registration (JSON, used by files.js) and
 * file content. The pages are Livewire (resources/views/pages/files).
 */
class FileController extends Controller
{
    public function __construct(private PersonalFiles $files) {}

    /** Step 1 of an upload from the page: reserve quota and register the entry. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'uuid'],
            'bytes' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'integer'],
        ]);
        // A foreign or missing folder stays a 404; domain refusals are written for people.
        $file = DomainErrors::guard('upload', fn () => $this->files->begin(
            $request->user(),
            isset($data['folder']) ? (int) $data['folder'] : null,
            $data['name'],
            (int) $data['bytes'],
            $data['key'],
        ));

        return response()->json(['id' => $file->id, 'upload' => $file->upload_id, 'name' => $file->name], 201);
    }

    public function content(Request $request, int $fileId): BinaryFileResponse
    {
        $file = $this->files->file($request->user(), $fileId);
        Gate::authorize('view', $file);
        try {
            $readable = $this->files->readable($request->user(), $file);
        } catch (RuntimeException) {
            abort(404);
        }
        $kind = FilePreview::kind($readable['mime'], $file->name);
        $inline = ! $request->boolean('download') && $kind !== 'none';
        $type = match (true) {
            ! $inline => 'application/octet-stream',
            in_array($kind, ['text', 'markdown'], true) => 'text/plain; charset=utf-8',
            default => $readable['mime'],
        };
        $response = response()->file($readable['path'], [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            // Browsers refuse to run their PDF viewer inside a CSP sandbox.
            'Content-Security-Policy' => $inline && $kind === 'pdf'
                ? "default-src 'none'; object-src 'self'; frame-ancestors 'self'"
                : "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox",
        ]);
        $fallback = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($file->name));
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $file->name, trim($fallback, ' .') !== '' ? $fallback : 'archivo');

        return $response;
    }
}
