<?php

namespace App\Actions\Assistant;

use App\Models\AssistantMember;
use App\Models\User;
use App\Services\AdminAudit;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Lets a person use the assistant or takes it away. Owner only; the owner can always use it.
 * Taking it away applies to the next tool call: access is checked on every call.
 */
class SetAssistantMember
{
    public function __construct(private AdminAudit $audit) {}

    public function handle(User $actor, User $target, bool $allowed): void
    {
        DB::transaction(function () use ($actor, $target, $allowed): void {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('manage-assistant');
            if ($target->role === 'owner') {
                return;
            }
            $member = AssistantMember::whereKey($target->id)->first();
            if (($member !== null) === $allowed) {
                return;
            }
            if ($allowed) {
                $member = new AssistantMember;
                $member->user_id = $target->id;
                $member->granted_by_id = $actor->id;
                $member->save();
            } else {
                $member?->delete();
            }
            $this->audit->recordAssistant($actor, $target, $allowed ? 'assistant.member_added' : 'assistant.member_removed');
        });
    }
}
