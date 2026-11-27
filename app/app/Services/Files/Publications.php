<?php

namespace App\Services\Files;

use App\Enums\PublicationState;
use App\Enums\Role;
use App\Enums\UploadState;
use App\Models\Folder;
use App\Models\PrivateUpload;
use App\Models\Publication;
use App\Models\User;
use App\Models\UserFile;
use App\Services\AdminAudit;
use App\Services\Storage\PrivateUploads;
use App\Services\Storage\StorageReservations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Publication for everyone (S04b, docs/STORAGE.md). Decisions of the user (2026-11-22):
 * - the contributor asks; only an administrator publishes, withdraws or deletes;
 * - published bytes leave the contributor's quota and join the common fund;
 * - published content stays when the contributor's account is deleted (common property).
 * A file is only published after its common library is linked and granted in the service.
 */
class Publications
{
    public function __construct(
        private CommonLibrary $library,
        private StorageReservations $reservations,
        private PrivateUploads $uploads,
        private MediaLibrary $media,
        private AdminAudit $audit,
    ) {}

    public static function canDecide(User $user): bool
    {
        return in_array($user->role, [Role::Owner->value, Role::Admin->value], true);
    }

    /** The publication of a person's file, if any (requested or published). */
    public function forFile(UserFile $file): ?Publication
    {
        return Publication::where('upload_id', $file->upload_id)->first();
    }

    /** Files that cannot be renamed, trashed, replaced or deleted by their contributor. */
    public function locks(UserFile $file): bool
    {
        return Publication::where('upload_id', $file->upload_id)->exists();
    }

    public function request(User $user, int $fileId): Publication
    {
        return DB::transaction(function () use ($user, $fileId): Publication {
            $file = UserFile::where('user_id', $user->id)->lockForUpdate()->find($fileId)
                ?? throw ValidationException::withMessages(['publication' => 'Ese archivo ya no está en Mis archivos.']);
            $connection = $this->connectionOf($user, $file);
            $upload = PrivateUpload::whereKey($file->upload_id)->firstOrFail();
            if ($file->trashed_at !== null || $file->version_of_id !== null || $upload->state !== UploadState::Ready->value) {
                throw ValidationException::withMessages(['publication' => 'Solo se puede proponer un archivo guardado y fuera de la papelera.']);
            }
            $existing = Publication::where('upload_id', $file->upload_id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $publication = new Publication;
            $publication->upload_id = $file->upload_id;
            $publication->connection = $connection;
            $publication->title = $file->name;
            $publication->bytes = (int) DB::table('storage_reservations')->where('id', $file->upload_id)->value('actual_bytes');
            $publication->contributor_id = $user->id;
            $publication->user_file_id = $file->id;
            $publication->state = PublicationState::Requested->value;
            $publication->requested_at = now();
            $publication->save();

            return $publication;
        });
    }

    /**
     * Several of the person's files at once (Mis archivos selection bar), all or nothing: a file
     * that cannot be proposed cancels the whole batch and the message names it. Files already
     * proposed or published keep their publication.
     *
     * @param  list<int>  $fileIds
     * @return list<Publication>
     */
    public function requestMany(User $user, array $fileIds): array
    {
        return DB::transaction(function () use ($user, $fileIds): array {
            $publications = [];
            foreach ($fileIds as $fileId) {
                try {
                    $publications[] = $this->request($user, $fileId);
                } catch (ValidationException $exception) {
                    $name = UserFile::where('user_id', $user->id)->whereKey($fileId)->value('name');
                    $message = (string) collect($exception->errors())->flatten()->first();
                    throw ValidationException::withMessages(['publication' => is_string($name) ? '«'.$name.'»: '.$message : $message]);
                }
            }

            return $publications;
        });
    }

    /**
     * Sends the person's files to the common fund as their role allows: an administrator
     * publishes them straight away (same checks as Administración → Para todos), anyone else
     * proposes them. If publishing fails the proposals stay pending for a later decision.
     *
     * @param  list<int>  $fileIds
     * @return array{published: int, requested: int}
     */
    public function share(User $user, array $fileIds): array
    {
        $publications = $this->requestMany($user, $fileIds);
        $pending = array_values(array_map(fn (Publication $p): int => $p->id,
            array_filter($publications, fn (Publication $p): bool => $p->state === PublicationState::Requested->value)));
        if (! Gate::forUser($user)->allows('publish-content') || $pending === []) {
            return ['published' => 0, 'requested' => count($pending)];
        }
        try {
            return ['published' => $this->publishMany($user, $pending), 'requested' => 0];
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            throw ValidationException::withMessages(['publication' => $message.' Han quedado propuestos en Administración → Para todos.']);
        }
    }

    /** The contributor changes their mind before a decision. */
    public function cancel(User $user, int $id): void
    {
        $deleted = Publication::whereKey($id)->where('contributor_id', $user->id)
            ->where('state', PublicationState::Requested->value)->delete();
        if ($deleted === 0) {
            throw ValidationException::withMessages(['publication' => 'Esa propuesta ya no está pendiente.']);
        }
    }

    /** The contributor asks an administrator to withdraw it (only administrators withdraw). */
    public function askWithdrawal(User $user, int $id): void
    {
        $updated = Publication::whereKey($id)->where('contributor_id', $user->id)
            ->where('state', PublicationState::Published->value)->whereNull('withdrawal_requested_at')
            ->update(['withdrawal_requested_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['publication' => 'No se puede pedir la retirada de esa publicación.']);
        }
    }

    /**
     * Links the file in the common library and grants it in the service first; only then moves
     * the bytes to the common fund and confirms. Any failure leaves it requested and unlinked.
     */
    public function publish(User $actor, int $id): Publication
    {
        $this->authorize($actor);
        if (! Publication::whereKey($id)->where('state', PublicationState::Requested->value)->exists()) {
            throw ValidationException::withMessages(['publication' => 'Esa propuesta ya no está pendiente.']);
        }
        $this->publishMany($actor, [$id]);

        return Publication::findOrFail($id);
    }

    /**
     * Several pending proposals, all or nothing: one pass links them, each service grants its
     * common library once, and a single transaction moves the bytes and confirms them all.
     *
     * @param  list<int>  $ids
     * @return int how many were published
     */
    public function publishMany(User $actor, array $ids): int
    {
        $this->authorize($actor);
        $connections = Publication::whereIn('id', $ids)->where('state', PublicationState::Requested->value)
            ->distinct()->pluck('connection')->all();
        if ($connections === []) {
            return 0;
        }
        try {
            $this->library->sync(include: $ids);
            foreach ($connections as $connection) {
                $this->library->grant((string) $connection);
            }
        } catch (RuntimeException) {
            $this->unlinkQuietly();
            throw ValidationException::withMessages(['publication' => 'El servicio no ha confirmado el acceso para todos. No se ha publicado; inténtalo de nuevo.']);
        }

        try {
            return DB::transaction(function () use ($actor, $ids): int {
                $published = 0;
                foreach ($ids as $id) {
                    $published += $this->confirm($actor, $id) ? 1 : 0;
                }

                return $published;
            });
        } catch (Throwable $exception) {
            $this->unlinkQuietly(); // linked and granted, but not confirmed: take them back
            throw $exception;
        }
    }

    /** @return bool whether it was published now (false: someone already did) */
    private function confirm(User $actor, int $id): bool
    {
        return DB::transaction(function () use ($actor, $id): bool {
            $publication = Publication::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($publication->state === PublicationState::Published->value) {
                return false;
            }
            $contributor = $publication->contributor_id === null ? null : User::find($publication->contributor_id);
            if ($contributor === null) {
                throw ValidationException::withMessages(['publication' => 'Quien lo propuso ya no tiene cuenta.']);
            }
            $this->reservations->moveToCommon($contributor, $publication->upload_id);
            $publication->state = PublicationState::Published->value;
            $publication->decided_by = $actor->id;
            $publication->decided_at = now();
            $publication->save();
            $this->media->changed($contributor); // their personal copy leaves their library: it is common now
            $this->record($actor, $publication, 'content.published');

            return true;
        });
    }

    public function reject(User $actor, int $id): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $id): void {
            $publication = Publication::whereKey($id)->where('state', PublicationState::Requested->value)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['publication' => 'Esa propuesta ya no está pendiente.']);
            $this->record($actor, $publication, 'content.rejected');
            $publication->delete();
        });
    }

    /**
     * Stops showing it to everyone and gives it back to its contributor (bytes back to their
     * quota, fail closed). Without a contributor, the only choice is deleting it.
     */
    public function withdraw(User $actor, int $id): void
    {
        $this->authorize($actor);
        $publication = Publication::whereKey($id)->where('state', PublicationState::Published->value)->first()
            ?? throw ValidationException::withMessages(['publication' => 'Esa publicación ya no existe.']);
        $contributor = $publication->contributor_id === null ? null : User::find($publication->contributor_id);
        if ($contributor === null || $publication->user_file_id === null) {
            throw ValidationException::withMessages(['publication' => 'Quien lo aportó ya no tiene cuenta: solo se puede eliminar del NAS.']);
        }
        DB::transaction(function () use ($actor, $publication, $contributor): void {
            Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            try {
                $this->reservations->moveFromCommon($contributor, $publication->upload_id);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['publication' => $exception->getMessage().' Amplía su espacio o elimínalo del NAS.']);
            }
            $this->record($actor, $publication, 'content.withdrawn');
            $publication->delete();
            $this->media->changed($contributor);
        });
        $this->unlinkQuietly();
    }

    /** Deletes common content from the NAS for good (frees the common fund and the disk). */
    public function destroy(User $actor, int $id): void
    {
        $this->authorize($actor);
        $publication = Publication::whereKey($id)->where('state', PublicationState::Published->value)->first()
            ?? throw ValidationException::withMessages(['publication' => 'Esa publicación ya no existe.']);
        $this->library->sync(exclude: [$publication->id]); // unlinked from the service first
        try {
            $this->deleteConfirmed($actor, $publication);
        } catch (Throwable $exception) {
            $this->unlinkQuietly(); // not deleted: still published, so link it again
            throw $exception;
        }
    }

    private function deleteConfirmed(User $actor, Publication $publication): void
    {
        DB::transaction(function () use ($actor, $publication): void {
            $locked = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($locked->user_file_id !== null) {
                UserFile::whereKey($locked->user_file_id)->delete(); // the contributor's entry goes too
            }
            try {
                $this->uploads->purgeCommon($locked->upload_id);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['publication' => $exception->getMessage()]);
            }
            $this->record($actor, $locked, 'content.deleted');
            $locked->delete();
        });
    }

    /**
     * Before an account is deleted: published content becomes common property (its row stays,
     * the contributor's entry in Mis archivos goes); pending proposals are dropped.
     */
    public function releaseContributor(User $user): void
    {
        Publication::where('contributor_id', $user->id)->where('state', PublicationState::Requested->value)->delete();
        $entries = Publication::where('contributor_id', $user->id)->whereNotNull('user_file_id')->pluck('user_file_id');
        Publication::where('contributor_id', $user->id)->update(['user_file_id' => null, 'withdrawal_requested_at' => null]);
        UserFile::whereIn('id', $entries)->delete();
    }

    /** @return Collection<int, Publication> */
    public function pending(): Collection
    {
        return Publication::with('contributor')->where('state', PublicationState::Requested->value)->orderBy('requested_at')->get();
    }

    /** @return Collection<int, Publication> */
    public function published(): Collection
    {
        return Publication::with('contributor')->where('state', PublicationState::Published->value)
            ->orderByRaw('withdrawal_requested_at is null')->orderByDesc('decided_at')->get();
    }

    private function connectionOf(User $user, UserFile $file): string
    {
        $folder = $file->folder_id === null ? null : Folder::where('user_id', $user->id)->find($file->folder_id);
        while ($folder !== null && $folder->media_link === null) {
            $folder = $folder->parent_id === null ? null : Folder::where('user_id', $user->id)->find($folder->parent_id);
        }
        $upload = PrivateUpload::whereKey($file->upload_id)->first();
        if ($folder === null || $upload === null || $upload->media_link === null) {
            throw ValidationException::withMessages(['publication' => 'Solo se puede proponer lo que está en una carpeta conectada (Música, Libros, Cómics…).']);
        }

        return (string) $folder->media_link;
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('publish-content');
    }

    /** Rebuilds the common library from what is published (nothing just linked stays). */
    private function unlinkQuietly(): void
    {
        try {
            $this->library->sync();
        } catch (RuntimeException) {
            // portal:media-sync converges the common library from the database later.
        }
    }

    private function record(User $actor, Publication $publication, string $action): void
    {
        $subject = $publication->contributor_id === null ? $actor : (User::find($publication->contributor_id) ?? $actor);
        $this->audit->recordContent($actor, $subject, $action, $publication->connection, $publication->bytes);
    }
}
