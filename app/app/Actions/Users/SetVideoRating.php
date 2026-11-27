<?php

namespace App\Actions\Users;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\JellyfinAccounts;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Parental limit for Vídeo, set by an administrator. Applied in Jellyfin itself (its user policy):
 * hiding a card would not stop its apps. If Jellyfin is not reachable now, the next media sync applies it.
 */
class SetVideoRating
{
    public function __construct(private AdminAudit $audit) {}

    public function handle(User $actor, User $target, ?int $rating): void
    {
        if ($rating !== null && ! array_key_exists($rating, JellyfinAccounts::AGE_LIMITS)) {
            throw ValidationException::withMessages(['video_max_rating' => 'Elige uno de los límites de la lista.']);
        }
        $changed = DB::transaction(function () use ($actor, $target, $rating): bool {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('update', $target);
            if ($target->video_max_rating === $rating) {
                return false;
            }
            $target->video_max_rating = $rating;
            $target->save();
            $this->audit->record($actor, $target, 'user.updated', ['video_max_rating']);

            return true;
        });
        $account = ServiceAccount::where('user_id', $target->id)->where('service', 'video')->first();
        if ($changed && $account !== null && config('provisioning.video.enabled')) {
            try {
                app(JellyfinAccounts::class)->applyPolicy($account);
            } catch (RuntimeException $exception) {
                Log::warning('video rating pending', ['user' => $target->id, 'reason' => $exception->getMessage()]);
            }
        }
    }
}
