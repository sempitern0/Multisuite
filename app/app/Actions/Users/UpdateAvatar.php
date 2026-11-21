<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Users\Avatars;
use App\Support\ProfileStickers;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A person replaces their own picture: an uploaded photo or a character from ProfileStickers.
 * The old photo file is removed only once the change is recorded.
 */
class UpdateAvatar
{
    public function __construct(private Avatars $avatars) {}

    public function handle(User $actor, User $target, UploadedFile $file): void
    {
        Gate::forUser($actor)->authorize('changeAvatar', $target);
        // Image work happens before any lock is taken.
        $hash = $this->avatars->store($target->id, $file);
        try {
            // The character is kept: removing the photo later shows it again.
            $this->swap($target, function (User $locked) use ($hash): void {
                $locked->avatar_hash = $hash;
            });
        } catch (Throwable $exception) {
            if (User::whereKey($target->id)->value('avatar_hash') !== $hash) {
                $this->avatars->delete($target->id, $hash);
            }
            throw $exception;
        }
    }

    public function selectSticker(User $actor, User $target, ?string $sticker): void
    {
        Gate::forUser($actor)->authorize('changeAvatar', $target);
        if ($sticker !== null && ! isset(ProfileStickers::LABELS[$sticker])) {
            throw ValidationException::withMessages(['sticker' => 'Elige uno de los personajes de la lista.']);
        }
        // Choosing a character (or initials, null) replaces the photo.
        $this->swap($target, function (User $locked) use ($sticker): void {
            $locked->avatar_hash = null;
            $locked->avatar_sticker = $sticker;
        });
    }

    /**
     * Applies $change to the locked row and deletes the photo file it no longer points to.
     *
     * @param  Closure(User): void  $change
     */
    private function swap(User $target, Closure $change): void
    {
        DB::transaction(function () use ($target, $change): void {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->avatar_hash;
            $change($locked);
            $locked->save();
            if ($previous !== null && $previous !== $locked->avatar_hash) {
                DB::afterCommit(fn () => $this->avatars->delete($locked->id, $previous));
            }
        });
    }
}
