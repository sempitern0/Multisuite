<?php

namespace App\Http\Controllers;

use App\Models\PrivateUpload;
use App\Models\StorageReservation;
use App\Services\Storage\PrivateUploads;
use App\Services\Storage\UnsafeImageMetadata;
use App\Services\Storage\UploadOffsetMismatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PrivateUploadController extends Controller
{
    public function store(Request $request, PrivateUploads $uploads): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'uuid'], 'bytes' => ['required', 'integer', 'min:1']]);
        try {
            $upload = $uploads->begin($request->user(), (int) $data['bytes'], $data['key']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['upload' => 'Operación no completada. Comprueba el estado, los límites y la ubicación; cancela los intentos incompletos.']);
        }

        return response()->json(['id' => $upload->id, 'state' => $upload->state], 201);
    }

    public function show(Request $request, string $key, PrivateUploads $uploads): JsonResponse
    {
        StorageReservation::whereKey($key)->where('user_id', $request->user()->id)->firstOrFail();
        $upload = PrivateUpload::findOrFail($key);
        try {
            $received = $uploads->received($request->user(), $key);
        } catch (RuntimeException) {
            $received = 0;
        }

        return response()->json(['id' => $upload->id, 'state' => $upload->state, 'received' => $received]);
    }

    public function update(Request $request, string $key, PrivateUploads $uploads): JsonResponse
    {
        StorageReservation::whereKey($key)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($request->header('Content-Type') === 'application/octet-stream', 415);
        $offset = $request->query('offset', '0');
        abort_unless(is_string($offset) && preg_match('/^\d{1,15}$/D', $offset) === 1, 400);
        $chunkSha256 = $request->header('X-Chunk-SHA256');
        abort_unless($chunkSha256 === null || preg_match('/^[a-f0-9]{64}$/D', $chunkSha256) === 1, 400);
        $input = $request->getContent(true);
        abort_unless(is_resource($input), 400);
        try {
            $upload = $uploads->receive($request->user(), $key, $input, (int) $offset, $chunkSha256);
        } catch (UploadOffsetMismatch $mismatch) {
            return response()->json(['state' => 'pending', 'received' => $mismatch->received], 409);
        } catch (UnsafeImageMetadata) {
            throw ValidationException::withMessages(['upload' => 'No se ha podido quitar la información privada de esta foto (ubicación, cámara…), así que no se ha guardado. Prueba con otra copia o cancela la subida.']);
        } catch (RuntimeException) {
            // Retain the reservation on error; explicit cancellation or expiry cleanup
            // owns deletion. Never release from an uncertain database commit outcome.
            throw ValidationException::withMessages(['upload' => 'Operación no completada. Comprueba el estado, los límites y la ubicación; cancela los intentos incompletos.']);
        } finally {
            fclose($input);
        }

        return response()->json(['id' => $upload->id, 'state' => $upload->state, 'received' => $uploads->received($request->user(), $key)]);
    }

    public function destroy(Request $request, string $key, PrivateUploads $uploads): JsonResponse
    {
        StorageReservation::whereKey($key)->where('user_id', $request->user()->id)->firstOrFail();
        try {
            $uploads->cancel($request->user(), $key);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['upload' => 'Operación no completada. Comprueba el estado, los límites y la ubicación; cancela los intentos incompletos.']);
        }

        return response()->json(['state' => 'cancelled']);
    }
}
