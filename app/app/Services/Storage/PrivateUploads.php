<?php

namespace App\Services\Storage;

use App\Models\PrivateUpload;
use App\Models\StorageReservation;
use App\Models\User;
use finfo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrivateUploads
{
    public function __construct(private StorageReservations $reservations, private StorageInventory $inventory) {}

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

    /** @param resource $input */
    public function receive(User $user, string $key, $input): PrivateUpload
    {
        if (! config('nas.uploads_enabled')) {
            throw new RuntimeException('Subidas deshabilitadas.');
        }

        // A database lock serializes writers and cleanup, including across hosts.
        // Do not retry this transaction: the input stream cannot be replayed.
        return DB::transaction(function () use ($user, $key, $input): PrivateUpload {
            [$upload, $reservation, $base] = $this->locked($user, $key);
            if ($upload->state === 'ready') {
                return $upload;
            }
            if ($upload->state !== 'pending' || $reservation->state !== 'reserved' || $reservation->expires_at->isPast()) {
                throw new RuntimeException('La subida no está disponible.');
            }
            $part = $base.'.part';
            $final = $base.'.blob';
            if (file_exists($part) || is_link($part) || file_exists($final) || is_link($final)) {
                throw new RuntimeException('Quedan datos de un intento anterior. Cancela la subida antes de empezar otra.');
            }
            $output = @fopen($part, 'x+b');
            if ($output === false) {
                throw new RuntimeException('No se puede crear el archivo privado.');
            }
            try {
                if (! chmod($part, 0600)) {
                    throw new RuntimeException('No se pueden restringir los permisos.');
                }
                $size = 0;
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
                if ($size !== $reservation->bytes || ! fflush($output) || ! fsync($output)) {
                    throw new RuntimeException('El tamaño recibido no coincide o no se ha persistido.');
                }
            } finally {
                fclose($output);
            }
            clearstatcache(true, $part);
            $actual = filesize($part);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($part);
            $hash = hash_file('sha256', $part);
            if ($actual !== $reservation->bytes || $hash === false || ! in_array($mime, [
                'audio/mpeg', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'application/ogg',
                'audio/wav', 'audio/x-wav', 'application/pdf', 'text/plain',
            ], true)) {
                throw new RuntimeException('Tamaño o formato no permitido.');
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

            return $upload;
        });
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
