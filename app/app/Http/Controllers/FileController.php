<?php

namespace App\Http\Controllers;

use App\Models\StorageSetting;
use App\Models\UserFile;
use App\Models\UserStorageQuota;
use App\Services\Files\PersonalFiles;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Mis archivos. Read-only previews are limited to images, PDF (browser viewer),
 * sanitised Markdown and plain text; everything else is download-only.
 */
class FileController extends Controller
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json'];

    public function __construct(private PersonalFiles $files) {}

    public function index(Request $request, ?int $folderId = null): Response
    {
        $user = $request->user();
        $folder = $this->files->folder($user, $folderId);
        if ($folder !== null) {
            Gate::authorize('view', $folder);
        }
        $quota = UserStorageQuota::find($user->id);

        return response()->view('files.index', [
            'folder' => $folder,
            'breadcrumbs' => $this->files->breadcrumbs($folder),
            'folders' => $this->files->folders($user, $folder),
            'entries' => $this->files->files($user, $folder),
            'pending' => $this->files->pendingCount($user),
            'enabled' => (bool) config('nas.uploads_enabled'),
            'maxBytes' => (int) config('nas.upload_max_bytes'),
            'used' => (int) ($quota->used_bytes ?? 0),
            'limit' => (int) ($quota->limit_bytes ?? StorageSetting::defaultUserQuotaBytes()),
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'parent' => ['nullable', 'integer']]);
        $folder = $this->guard('folder', fn () => $this->files->createFolder($request->user(), isset($data['parent']) ? (int) $data['parent'] : null, $data['name']));

        return redirect()->route('files.folder', $folder)->with('status', 'Carpeta «'.$folder->name.'» creada.');
    }

    public function updateFolder(Request $request, int $folderId): RedirectResponse
    {
        Gate::authorize('update', $this->files->folder($request->user(), $folderId));
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $folder = $this->guard('folder_name', fn () => $this->files->renameFolder($request->user(), $folderId, $data['name']));

        return redirect()->route('files.folder', $folder)->with('status', 'Carpeta renombrada.');
    }

    public function destroyFolder(Request $request, int $folderId): RedirectResponse
    {
        $folder = $this->files->folder($request->user(), $folderId);
        Gate::authorize('update', $folder);
        $parent = $folder?->parent_id;
        $this->guard('folder_delete', fn () => $this->files->deleteEmptyFolder($request->user(), $folderId));

        return ($parent === null ? redirect()->route('files.index') : redirect()->route('files.folder', $parent))
            ->with('status', 'Carpeta eliminada.');
    }

    /** Step 1 of an upload from the page: reserve quota and register the entry. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'uuid'],
            'bytes' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'integer'],
        ]);
        try {
            $file = $this->files->begin($request->user(), isset($data['folder']) ? (int) $data['folder'] : null, $data['name'], (int) $data['bytes'], $data['key']);
        } catch (RecordsNotFoundException $exception) {
            throw $exception; // Foreign or missing folder: 404, never a validation message.
        } catch (RuntimeException $exception) {
            // Domain messages are written for people and never contain paths.
            throw ValidationException::withMessages(['upload' => $exception->getMessage()]);
        }

        return response()->json(['id' => $file->id, 'upload' => $file->upload_id, 'name' => $file->name], 201);
    }

    public function update(Request $request, int $fileId): RedirectResponse
    {
        Gate::authorize('update', $this->files->file($request->user(), $fileId));
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $file = $this->guard('file_name', fn () => $this->files->renameFile($request->user(), $fileId, $data['name']));

        return redirect()->route('files.show', $file)->with('status', 'Archivo renombrado.');
    }

    public function show(Request $request, int $fileId): Response
    {
        $file = $this->files->file($request->user(), $fileId);
        Gate::authorize('view', $file);
        $error = null;
        $kind = 'none';
        $text = null;
        $html = null;
        $truncated = false;
        $readable = ['mime' => '', 'size' => 0];
        try {
            $readable = $this->files->readable($request->user(), $file);
            $kind = $this->kind($readable['mime'], $file->name);
            if (in_array($kind, ['text', 'markdown'], true)) {
                $max = (int) config('nas.preview_text_max_bytes');
                $raw = (string) file_get_contents($readable['path'], false, null, 0, $max + 1);
                $truncated = strlen($raw) > $max;
                $text = mb_scrub(substr($raw, 0, $max), 'UTF-8');
                if ($kind === 'markdown' && ! $truncated) {
                    // Raw HTML stripped and unsafe link schemes refused: output is safe to print.
                    $html = Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
                }
            }
        } catch (RecordsNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return response()->view('files.show', [
            'file' => $file,
            'breadcrumbs' => $this->files->breadcrumbs($this->files->folder($request->user(), $file->folder_id)),
            'kind' => $kind,
            'mime' => $readable['mime'],
            'size' => $readable['size'],
            'text' => $text,
            'html' => $html,
            'truncated' => $truncated,
            'error' => $error,
        ])->header('Content-Security-Policy', "img-src 'self' data:");
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
        $kind = $this->kind($readable['mime'], $file->name);
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
                : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox",
        ]);
        $fallback = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($file->name));
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $file->name, trim($fallback, ' .') !== '' ? $fallback : 'archivo');

        return $response;
    }

    private function kind(string $mime, string $name): string
    {
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            in_array($mime, self::IMAGE_MIMES, true) => 'image',
            $mime === 'application/pdf' => 'pdf',
            $mime === 'text/markdown', $mime === 'text/plain' && in_array($extension, ['md', 'markdown'], true) => 'markdown',
            in_array($mime, self::TEXT_MIMES, true) => 'text',
            default => 'none',
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guard(string $field, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof RecordsNotFoundException) {
                throw $exception;
            }
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
