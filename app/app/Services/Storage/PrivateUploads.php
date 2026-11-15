<?php

namespace App\Services\Storage;

use App\Events\PrivateUploadReady;
use App\Models\PrivateUpload;
use App\Models\StorageReservation;
use App\Models\User;
use finfo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrivateUploads
{
    /** Idle time an upload may wait between chunks before cleanup reclaims it. */
    public const RESERVATION_MINUTES = 30;

    public function __construct(private StorageReservations $reservations, private StorageInventory $inventory, private LosslessOptimizer $optimizer) {}

    public function begin(User $user, int $bytes, string $key): PrivateUpload
    {
        if (! config('nas.uploads_enabled') || $bytes < 1 || $bytes > (int) config('nas.upload_max_bytes')) {
            throw new RuntimeException('Subida deshabilitada o tamaño no permitido.');
        }
        [$root, $pool, $location] = $this->destination();

        return DB::transaction(function () use ($user, $bytes, $key, $root, $pool, $location): PrivateUpload {
            $this->reservations->reserve($user, $pool, $bytes, $key);
            $upload = PrivateUpload::find($key);
            if ($upload !== null) {
                $this->checkRoot($upload, $root, $location);

                return $upload;
            }
            $reservation = StorageReservation::findOrFail($key);
            if ($reservation->state !== 'reserved' || $reservation->expires_at->isPast()) {
                throw new RuntimeException('Reserva no disponible.');
            }
            $upload = new PrivateUpload;
            $upload->id = $key;
            $upload->location_id = $location;
            $upload->root_hash = hash('sha256', $root);
            $upload->state = 'pending';
            $upload->save();

            return $upload;
        });
    }

    /**
     * Appends one chunk (or the whole body) at $offset. Chunks keep every request under
     * the web server and PHP body limits, so a file is bounded only by NAS_UPLOAD_MAX_BYTES
     * and the quota. The last chunk verifies type, optimises losslessly, hashes and commits.
     *
     * @param  resource  $input
     */
    public function receive(User $user, string $key, $input, int $offset = 0): PrivateUpload
    {
        if (! config('nas.uploads_enabled')) {
            throw new RuntimeException('Subidas deshabilitadas.');
        }

        // A database lock serializes chunks, writers and cleanup, including across hosts.
        // Do not retry this transaction: the input stream cannot be replayed.
        return DB::transaction(function () use ($user, $key, $input, $offset): PrivateUpload {
            [$upload, $reservation, $base] = $this->locked($user, $key);
            if ($upload->state === 'ready') {
                return $upload;
            }
            if ($upload->state !== 'pending' || $reservation->state !== 'reserved' || $reservation->expires_at->isPast()) {
                throw new RuntimeException('La subida no está disponible.');
            }
            $part = $base.'.part';
            $final = $base.'.blob';
            if (is_link($part) || file_exists($final) || is_link($final) || (file_exists($part) && ! is_file($part))) {
                throw new RuntimeException('Quedan datos de un intento anterior. Cancela la subida antes de empezar otra.');
            }
            $received = $this->receivedBytes($part);
            if ($offset !== $received) {
                throw new UploadOffsetMismatch($received);
            }
            $output = @fopen($part, 'ab');
            if ($output === false) {
                throw new RuntimeException('No se puede crear el archivo privado.');
            }
            try {
                if (! chmod($part, 0600)) {
                    throw new RuntimeException('No se pueden restringir los permisos.');
                }
                $size = $received;
                while (! feof($input)) {
                    $chunk = fread($input, 65536);
                    if ($chunk === false || ($chunk === '' && ! feof($input))) {
                        throw new RuntimeException('Lectura interrumpida.');
                    }
                    $size += strlen($chunk);
                    if ($size > $reservation->bytes) {
                        throw new RuntimeException('El archivo supera la reserva.');
                    }
                    if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Escritura incompleta; revisa el espacio disponible.');
                    }
                }
                if (! fflush($output) || ! fsync($output)) {
                    throw new RuntimeException('No se ha podido persistir el fragmento.');
                }
            } finally {
                fclose($output);
            }
            if ($size < $reservation->bytes) {
                // Still arriving: keep the reservation alive while chunks keep coming.
                $this->reservations->extend($user, $key, self::RESERVATION_MINUTES);

                return $upload;
            }
            clearstatcache(true, $part);
            $actual = filesize($part);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($part);
            if ($actual !== $reservation->bytes || $mime === false || ! in_array($mime, (array) config('nas.allowed_mimes', []), true)) {
                throw new RuntimeException('Tamaño o formato no permitido.');
            }
            // Same picture in fewer bytes when possible; the person is charged the final size.
            $this->optimizer->optimize($part, $mime);
            clearstatcache(true, $part);
            $actual = filesize($part);
            $hash = hash_file('sha256', $part);
            if ($actual === false || $actual < 1 || $actual > $reservation->bytes || $hash === false) {
                throw new RuntimeException('No se puede verificar el archivo recibido.');
            }
            if (! rename($part, $final)) {
                throw new RuntimeException('No se puede finalizar el archivo.');
            }
            // A crash/rollback leaves pending + reserved, with bytes for cleanup.
            $this->reservations->commit($user, $key, $actual);
            $upload->state = 'ready';
            $upload->mime = $mime;
            $upload->sha256 = $hash;
            $upload->save();
            // Synchronous, inside this transaction: consumers (e.g. file versions) apply atomically.
            event(new PrivateUploadReady($upload->id, $user->id));

            return $upload;
        });
    }

    /** Bytes stored so far for a pending upload owned by the user (resume after a failed chunk). */
    public function received(User $user, string $key): int
    {
        $upload = PrivateUpload::whereKey($key)->firstOrFail();
        $reservation = StorageReservation::whereKey($key)->where('user_id', $user->id)->firstOrFail();
        if ($upload->state === 'ready') {
            return (int) $reservation->actual_bytes;
        }
        if ($upload->state !== 'pending') {
            return 0;
        }
        [$root, , $location] = $this->destination();
        $this->checkRoot($upload, $root, $location);

        return $this->receivedBytes($root.'/'.$upload->id.'.part');
    }

    private function receivedBytes(string $part): int
    {
        clearstatcache(true, $part);
        if (! file_exists($part)) {
            return 0;
        }
        $size = filesize($part);
        if (is_link($part) || ! is_file($part) || $size === false) {
            throw new RuntimeException('El archivo parcial no es válido.');
        }

        return $size;
    }

    public function cancel(User $user, string $key): void
    {
        DB::transaction(function () use ($user, $key): void {
            [$upload, $reservation, $base] = $this->locked($user, $key);
            if ($upload->state === 'cancelled') {
                return;
            }
            if ($upload->state !== 'pending' || $reservation->state !== 'reserved') {
                throw new RuntimeException('No se puede cancelar contenido confirmado.');
            }
            foreach (['.part', '.blob'] as $suffix) {
                $path = $base.$suffix;
                if (is_link($path) || (file_exists($path) && (! is_file($path) || ! unlink($path)))) {
                    throw new RuntimeException('Limpieza incompleta: se conserva la reserva.');
                }
                clearstatcache(true, $path);
                if (file_exists($path) || is_link($path)) {
                    throw new RuntimeException('No se ha confirmado la eliminación.');
                }
            }
            $this->reservations->release($user, $key);
            $upload->state = 'cancelled';
            $upload->save();
        });
    }

    /**
     * Permanent deletion of a confirmed blob: unlink, verify it is gone, then return the
     * bytes to the quota in the same transaction. A missing blob is treated as already
     * deleted; an unavailable volume or a symlink keeps everything counted (fail closed).
     */
    public function purge(User $user, string $key): void
    {
        DB::transaction(function () use ($user, $key): void {
            [$upload, , $base] = $this->locked($user, $key);
            if ($upload->state === 'purged') {
                return;
            }
            if ($upload->state !== 'ready') {
                throw new RuntimeException('Solo se puede borrar contenido confirmado.');
            }
            $path = $base.'.blob';
            if (is_link($path) || (file_exists($path) && (! is_file($path) || ! unlink($path)))) {
                throw new RuntimeException('No se pudo borrar el archivo del disco; se conserva y sigue contando en la cuota.');
            }
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('No se ha podido confirmar el borrado en el disco.');
            }
            $this->reservations->releaseCommitted($user, $key);
            $upload->state = 'purged';
            $upload->save();
        });
    }

    /**
     * Absolute path of a confirmed blob owned by the user, for streaming only.
     * The root identity, state and file type are re-checked on every read.
     */
    public function readablePath(User $user, string $key): string
    {
        $reservation = StorageReservation::whereKey($key)->where('user_id', $user->id)->firstOrFail();
        $upload = PrivateUpload::whereKey($key)->firstOrFail();
        if ($upload->state !== 'ready' || $reservation->state !== 'committed') {
            throw new RuntimeException('El archivo no está disponible.');
        }
        [$root, , $location] = $this->destination();
        $this->checkRoot($upload, $root, $location);
        $path = $root.'/'.$upload->id.'.blob';
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('El archivo no está disponible en el disco.');
        }

        return $path;
    }

    /** @return array{PrivateUpload, StorageReservation, string} */
    private function locked(User $user, string $key): array
    {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $reservation = StorageReservation::whereKey($key)->where('user_id', $user->id)->firstOrFail();
        $upload = PrivateUpload::whereKey($key)->lockForUpdate()->firstOrFail();
        [$root, $pool, $location] = $this->destination();
        $this->checkRoot($upload, $root, $location);
        if ($pool !== $reservation->pool_id) {
            throw new RuntimeException('La ubicación ha cambiado de grupo.');
        }

        return [$upload, $reservation, $root.'/'.$upload->id];
    }

    private function checkRoot(PrivateUpload $upload, string $root, string $location): void
    {
        if ($upload->location_id !== $location || $upload->root_hash !== hash('sha256', $root)) {
            throw new RuntimeException('La ubicación ha cambiado: requiere reconciliación.');
        }
    }

    /**
     * The verified private directory, its capacity group and location ID, for other
     * private artefacts (ZIP exports) that must live next to the blobs.
     *
     * @return array{string, string, string}
     */
    public function privateArea(): array
    {
        return $this->destination();
    }

    /** @return array{string, string, string} */
    private function destination(): array
    {
        $this->inventory->inspect(); // Validate the full configuration before resolving a path.
        $volumes = json_decode((string) config('nas.volumes'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($volumes as $volume) {
            if ($volume['id'] !== config('nas.upload_location')) {
                continue;
            }
            if ((new VolumeProbe)->inspect($volume['path'], $volume['id'])['status'] !== 'observed') {
                break;
            }
            $root = realpath($volume['path']);
            if ($root === false) {
                break;
            }
            // Operator creates a dedicated private directory, never a service library.
            $private = $root.'/.multisuite-private';
            if (is_link($private) || ! is_dir($private) || ! is_writable($private) || (fileperms($private) & 0077) !== 0) {
                break;
            }

            return [$private, $volume['group'], $volume['id']];
        }
        throw new RuntimeException('No hay una ubicación privada disponible.');
    }
}
