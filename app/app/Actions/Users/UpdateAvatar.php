<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Users\Avatars;
use App\Support\ProfileStickers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A person replaces their own photo. The old file is removed only once the new one is recorded. */
class UpdateAvatar
{
    public function __construct(private Avatars $avatars) {}

    public function handle(User $actor, User $target, UploadedFile $file): void
    {
        Gate::forUser($actor)->authorize('changeAvatar', $target);
        // Image work happens before any lock is taken.
        $hash = $this->avatars->store($target->id, $file);
        try {
            $this->replace($target, $hash);
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
            throw ValidationException::withMessages(['sticker' => 'Elige un personaje de la colección.']);
        }
        $this->replace($target, null, $sticker);
    }

    private function replace(User $target, ?string $hash, ?string $sticker = null): void
    {
        DB::transaction(function () use ($target, $hash, $sticker): void {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->avatar_hash;
            $locked->avatar_hash = $hash;
            if ($hash === null) {
                $locked->avatar_sticker = $sticker;
            }
            $locked->save();
            if ($previous !== null && $previous !== $hash) {
                DB::afterCommit(fn () => $this->avatars->delete($locked->id, $previous));
            }
        });
    }
}
