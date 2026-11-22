<?php

namespace App\Services\Storage;

use App\Enums\ReservationState;
use App\Enums\UploadState;
use App\Events\PrivateUploadReady;
use App\Models\PrivateUpload;
use App\Models\StorageReservation;
use App\Models\User;
use App\Services\Files\ConnectedFolders;
use finfo;
use HashContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrivateUploads
{
    /** Idle time an upload may wait between chunks before cleanup reclaims it. */
    public const RESERVATION_MINUTES = 30;

    public function __construct(private StorageReservations $reservations, private StorageInventory $inventory, private LosslessOptimizer $optimizer, private ImageMetadata $metadata) {}

    /** $connection: a connected folder (ConnectedFolders) receives it unencrypted for its service. */
    public function begin(User $user, int $bytes, string $key, ?string $connection = null): PrivateUpload
    {
        if (! config('nas.uploads_enabled') || $bytes < 1 || $bytes > (int) config('nas.upload_max_bytes')) {
            throw new RuntimeException('Subida deshabilitada o tamaño no permitido.');
        }
        // Fail closed: with encryption on, nothing is stored until the key exists.
        $this->cipher();
        [$root, $pool, $location] = $this->destination();

        return DB::transaction(function () use ($user, $bytes, $key, $root, $pool, $location, $connection): PrivateUpload {
            $this->reservations->reserve($user, $pool, $bytes, $key);
            $upload = PrivateUpload::find($key);
            if ($upload !== null) {
                $this->checkRoot($upload, $root, $location);

                return $upload;
            }
            $reservation = StorageReservation::findOrFail($key);
            if ($reservation->state !== ReservationState::Reserved->value || $reservation->expires_at->isPast()) {
                throw new RuntimeException('Reserva no disponible.');
            }
            $upload = new PrivateUpload;
            $upload->id = $key;
            $upload->location_id = $location;
            $upload->root_hash = hash('sha256', $root);
            $upload->media_link = $connection;
            $upload->state = UploadState::Pending->value;
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
    public function receive(User $user, string $key, $input, int $offset = 0, ?string $chunkSha256 = null): PrivateUpload
    {
        if (! config('nas.uploads_enabled')) {
            throw new RuntimeException('Subidas deshabilitadas.');
        }

        // A database lock serializes chunks, writers and cleanup, including across hosts.
        // Do not retry this transaction: the input stream cannot be replayed.
        return DB::transaction(function () use ($user, $key, $input, $offset, $chunkSha256): PrivateUpload {
            [$upload, $reservation, $base] = $this->locked($user, $key);
            if ($upload->state === UploadState::Ready->value) {
                return $upload;
            }
            if ($upload->state !== UploadState::Pending->value || $reservation->state !== ReservationState::Reserved->value || $reservation->expires_at->isPast()) {
                throw new RuntimeException('La subida no está disponible.');
            }
            [$part, $sealed, $final] = [$base.'.part', $base.'.enc', $base.'.blob'];
            if (file_exists($final) || is_link($final)) {
                throw new RuntimeException('Quedan datos de un intento anterior. Cancela la subida antes de empezar otra.');
            }
            $received = $this->storedBytes($part, $sealed);
            if ($offset !== $received) {
                throw new UploadOffsetMismatch($received);
            }
            $data = $this->readChunk($input, $reservation->bytes - $received);
            if ($chunkSha256 !== null && ! hash_equals($chunkSha256, hash('sha256', $data))) {
                throw new RuntimeException('El fragmento recibido no coincide con el enviado.');
            }
            $last = $received + strlen($data) === $reservation->bytes;

            if ($received === 0) {
                // The type is decided by the first bytes, so a refused file stops at once.
                $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
                $allowed = [...(array) config('nas.allowed_mimes', []), ...($upload->media_link === null ? [] : ConnectedFolders::definition($upload->media_link)['mimes'])];
                if ($mime === false || ! in_array($mime, $allowed, true)) {
                    throw new RuntimeException('Tamaño o formato no permitido.');
                }
                $upload->mime = $mime;
                // Sealed as it arrives (no plaintext on disk), except pictures: their metadata is
                // removed (and a JPEG repacked) once the whole file is here, then they are sealed.
                // A connected folder keeps it unencrypted: its service reads the same bytes.
                $whole = $this->metadata->wants($mime) || $this->optimizer->wants($mime, $reservation->bytes);
                $upload->cipher = $this->cipher() !== null && ! $whole && $upload->media_link === null ? FileCipher::FORMAT : null;
            }
            $hash = $this->hashUpTo($upload, $part, $sealed, $received);
            hash_update($hash, $data);
            if ($upload->cipher !== null) {
                $this->cipherOrFail()->append($sealed, 'file:'.$upload->id, $data, $last);
            } else {
                $this->appendPlain($part, $data);
            }

            if (! $last) {
                $upload->hash_state = $this->packHash($hash, $received + strlen($data));
                $upload->save();
                // Still arriving: keep the reservation alive while chunks keep coming.
                $this->reservations->extend($user, $key, self::RESERVATION_MINUTES);

                return $upload;
            }

            $sha256 = hash_final($hash);
            $actual = $reservation->bytes;
            if ($upload->cipher !== null) {
                if (! rename($sealed, $final)) {
                    throw new RuntimeException('No se puede finalizar el archivo.');
                }
            } else {
                // Private metadata out (or the upload is refused), then the same picture in fewer
                // bytes when possible; the person is charged the final size.
                $this->metadata->strip($part, (string) $upload->mime);
                if ($this->optimizer->optimize($part, (string) $upload->mime) || $this->metadata->wants((string) $upload->mime)) {
                    clearstatcache(true, $part);
                    $actual = (int) filesize($part);
                    $sha256 = (string) hash_file('sha256', $part);
                }
                $this->publishWhole($upload, $part, $sealed, $final);
            }
            // A crash/rollback leaves pending + reserved, with bytes for cleanup.
            $this->reservations->commit($user, $key, $actual);
            $upload->state = UploadState::Ready->value;
            $upload->sha256 = $sha256;
            $upload->hash_state = null;
            $upload->save();
            // Synchronous, inside this transaction: consumers (e.g. file versions) apply atomically.
            event(new PrivateUploadReady($upload->id, $user->id));

            return $upload;
        });
    }

    /**
     * Reads one request body, refusing anything past the reserved size.
     *
     * @param  resource  $input
     */
    private function readChunk($input, int $remaining): string
    {
        $data = '';
        while (! feof($input)) {
            $piece = fread($input, 65536);
            if ($piece === false || ($piece === '' && ! feof($input))) {
                throw new RuntimeException('Lectura interrumpida.');
            }
            $data .= $piece;
            if (strlen($data) > $remaining) {
                throw new RuntimeException('El archivo supera la reserva.');
            }
        }
        if ($data === '' && $remaining > 0) {
            throw new RuntimeException('Fragmento vacío.');
        }

        return $data;
    }

    private function appendPlain(string $part, string $data): void
    {
        $output = @fopen($part, 'ab');
        if ($output === false) {
            throw new RuntimeException('No se puede crear el archivo privado.');
        }
        try {
            if (! chmod($part, 0600)) {
                throw new RuntimeException('No se pueden restringir los permisos.');
            }
            if (fwrite($output, $data) !== strlen($data)) {
                throw new RuntimeException('Escritura incompleta; revisa el espacio disponible.');
            }
            if (! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('No se ha podido persistir el fragmento.');
            }
        } finally {
            fclose($output);
        }
    }

    /** Whole-file uploads (encryption off, or an optimised JPEG): encrypt once if needed, then publish. */
    private function publishWhole(PrivateUpload $upload, string $part, string $sealed, string $final): void
    {
        $cipher = $upload->media_link === null ? $this->cipher() : null;
        if ($cipher === null) {
            // Connected files are also read by the service (through MediaLibrary's link); the
            // private directory itself stays 0700, so nobody else can reach them there.
            if (($upload->media_link !== null && ! chmod($part, 0644)) || ! rename($part, $final)) {
                throw new RuntimeException('No se puede finalizar el archivo.');
            }

            return;
        }
        if (file_exists($sealed) || is_link($sealed)) {
            throw new RuntimeException('Quedan restos de un cifrado anterior.');
        }
        try {
            $cipher->encryptFile($part, $sealed, 'file:'.$upload->id);
        } catch (RuntimeException $exception) {
            @unlink($sealed);
            throw $exception;
        }
        if (! rename($sealed, $final) || ! unlink($part)) {
            throw new RuntimeException('No se puede finalizar el archivo.');
        }
        $upload->cipher = FileCipher::FORMAT;
    }

    /**
     * Running SHA-256 of the bytes stored so far. The state is saved with each chunk; if a
     * crash left more bytes on disk than the state covers, the difference is read back.
     */
    private function hashUpTo(PrivateUpload $upload, string $part, string $sealed, int $stored): HashContext
    {
        $saved = $upload->hash_state === null ? null : json_decode((string) $upload->hash_state, true);
        $context = is_array($saved) && is_string($saved['context'] ?? null)
            ? unserialize((string) base64_decode($saved['context'], true), ['allowed_classes' => [HashContext::class]])
            : null;
        $covered = is_array($saved) && is_int($saved['bytes'] ?? null) ? $saved['bytes'] : 0;
        if (! $context instanceof HashContext || $covered > $stored) {
            [$context, $covered] = [hash_init('sha256'), 0];
        }
        if ($covered < $stored) {
            $update = function (string $piece) use ($context): void {
                hash_update($context, $piece);
            };
            if ($upload->cipher !== null) {
                $this->cipherOrFail()->read($sealed, 'file:'.$upload->id, $covered, $stored - $covered, $update);
            } else {
                (new StoredBlob($part, null, ''))->stream($covered, $stored - $covered, $update);
            }
        }

        return $context;
    }

    private function packHash(HashContext $context, int $bytes): string
    {
        return (string) json_encode(['bytes' => $bytes, 'context' => base64_encode(serialize($context))]);
    }

    private function cipherOrFail(): FileCipher
    {
        return $this->cipher() ?? throw new RuntimeException('Falta la clave de cifrado de archivos.');
    }

    /** Bytes stored so far for a pending upload owned by the user (resume after a failed chunk). */
    public function received(User $user, string $key): int
    {
        $upload = PrivateUpload::whereKey($key)->firstOrFail();
        $reservation = StorageReservation::whereKey($key)->where('user_id', $user->id)->firstOrFail();
        if ($upload->state === UploadState::Ready->value) {
            return (int) $reservation->actual_bytes;
        }
        if ($upload->state !== UploadState::Pending->value) {
            return 0;
        }
        [$root, , $location] = $this->destination();
        $this->checkRoot($upload, $root, $location);

        return $this->storedBytes($root.'/'.$upload->id.'.part', $root.'/'.$upload->id.'.enc');
    }

    /** Plaintext bytes received so far, whether sealed as they arrive (.enc) or kept whole (.part). */
    private function storedBytes(string $part, string $sealed): int
    {
        foreach ([$part, $sealed] as $path) {
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                throw new RuntimeException('El archivo parcial no es válido.');
            }
        }
        if (file_exists($sealed)) {
            return $this->cipherOrFail()->appendedBytes($sealed);
        }

        return file_exists($part) ? (int) filesize($part) : 0;
    }

    public function cancel(User $user, string $key): void
    {
        DB::transaction(function () use ($user, $key): void {
            [$upload, $reservation, $base] = $this->locked($user, $key);
            if ($upload->state === UploadState::Cancelled->value) {
                return;
            }
            if ($upload->state !== UploadState::Pending->value || $reservation->state !== ReservationState::Reserved->value) {
                throw new RuntimeException('No se puede cancelar contenido confirmado.');
            }
            foreach (['.part', '.enc', '.blob'] as $suffix) {
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
            $upload->state = UploadState::Cancelled->value;
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
            if ($upload->state === UploadState::Purged->value) {
                return;
            }
            if ($upload->state !== UploadState::Ready->value) {
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
            $upload->state = UploadState::Purged->value;
            $upload->save();
        });
    }

    /**
     * Permanent deletion of common content (published for everyone, maybe without a contributor
     * any more): same checks as purge(), then the bytes leave the pool and the common fund.
     */
    public function purgeCommon(string $key): void
    {
        DB::transaction(function () use ($key): void {
            $upload = PrivateUpload::whereKey($key)->lockForUpdate()->firstOrFail();
            if ($upload->state === UploadState::Purged->value) {
                return;
            }
            if ($upload->state !== UploadState::Ready->value) {
                throw new RuntimeException('Solo se puede borrar contenido confirmado.');
            }
            [$root, , $location] = $this->destination();
            $this->checkRoot($upload, $root, $location);
            $path = $root.'/'.$upload->id.'.blob';
            if (is_link($path) || (file_exists($path) && (! is_file($path) || ! unlink($path)))) {
                throw new RuntimeException('No se pudo borrar el archivo del disco; se conserva y sigue contando.');
            }
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('No se ha podido confirmar el borrado en el disco.');
            }
            $this->reservations->releaseCommon($key);
            $upload->state = UploadState::Purged->value;
            $upload->save();
        });
    }

    /**
     * A confirmed file owned by the user, ready to read as plaintext. The root identity,
     * state and file type are re-checked on every read.
     */
    public function blob(User $user, string $key): StoredBlob
    {
        $reservation = StorageReservation::whereKey($key)->where('user_id', $user->id)->firstOrFail();
        $upload = PrivateUpload::whereKey($key)->firstOrFail();
        if ($upload->state !== UploadState::Ready->value || $reservation->state !== ReservationState::Committed->value) {
            throw new RuntimeException('El archivo no está disponible.');
        }
        [$root, , $location] = $this->destination();
        $this->checkRoot($upload, $root, $location);
        $path = $root.'/'.$upload->id.'.blob';
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('El archivo no está disponible en el disco.');
        }

        return (new StoredBlob($path, $upload->cipher, 'file:'.$upload->id))->check();
    }

    /**
     * Path of a confirmed, unencrypted file of a connected folder (for MediaLibrary's links),
     * or null when it is not one. Same root and state checks as blob().
     */
    public function connectedPath(PrivateUpload $upload): ?string
    {
        if ($upload->state !== UploadState::Ready->value || $upload->media_link === null || $upload->cipher !== null) {
            return null;
        }
        [$root, , $location] = $this->destination();
        $this->checkRoot($upload, $root, $location);
        $path = $root.'/'.$upload->id.'.blob';
        clearstatcache(true, $path);

        return ! is_link($path) && is_file($path) ? $path : null;
    }

    /** The cipher for new files, or null when encryption is turned off. Throws when on without a key. */
    public function cipher(): ?FileCipher
    {
        if (! config('nas.encrypt_files')) {
            return null;
        }

        return FileCipher::fromConfig() ?? throw new RuntimeException('Falta la clave de cifrado de archivos: el responsable técnico debe ejecutar portal:files-key (docs/STORAGE.md).');
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
