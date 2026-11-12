<?php

namespace App\Http\Controllers;

use App\Models\PrivateUpload;
use App\Models\StorageReservation;
use App\Services\Storage\PrivateUploads;
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

    public function show(Request $request, string $key): JsonResponse
    {
        StorageReservation::whereKey($key)->where('user_id', $request->user()->id)->firstOrFail();
        $upload = PrivateUpload::findOrFail($key);

        return response()->json(['id' => $upload->id, 'state' => $upload->state]);
    }

    public function update(Request $request, string $key, PrivateUploads $uploads): JsonResponse
    {
        StorageReservation::whereKey($key)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($request->header('Content-Type') === 'application/octet-stream', 415);
        $input = $request->getContent(true);
        abort_unless(is_resource($input), 400);
        try {
            $upload = $uploads->receive($request->user(), $key, $input);
        } catch (RuntimeException) {
            // Retain the reservation on error; explicit cancellation or expiry cleanup
            // owns deletion. Never release from an uncertain database commit outcome.
            throw ValidationException::withMessages(['upload' => 'Operación no completada. Comprueba el estado, los límites y la ubicación; cancela los intentos incompletos.']);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        return response()->json(['id' => $upload->id, 'state' => $upload->state]);
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
