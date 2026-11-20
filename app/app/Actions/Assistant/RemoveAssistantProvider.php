<?php

namespace App\Actions\Assistant;

use App\Models\AssistantProvider;
use App\Models\User;
use App\Services\AdminAudit;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Deletes a provider and, with it, its encrypted key. Owner only. */
class RemoveAssistantProvider
{
    public function __construct(private AdminAudit $audit) {}

    public function handle(User $actor, int $providerId): void
    {
        DB::transaction(function () use ($actor, $providerId): void {
            [$actor] = LockedUsers::pair($actor->id, $actor->id);
            Gate::forUser($actor)->authorize('manage-assistant');
            $provider = AssistantProvider::lockForUpdate()->find($providerId);
            if ($provider === null) {
                return;
            }
            $provider->delete();
            $this->audit->recordAssistant($actor, $actor, 'assistant.provider_removed');
        });
    }
}
