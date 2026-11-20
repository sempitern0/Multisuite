<?php

namespace App\Actions\Assistant;

use App\Models\AssistantSetting;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Assistant\AssistantAccess;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Turns the assistant on or off, chooses its tools and the daily limits per person. Owner only. */
class UpdateAssistantSettings
{
    public const MAX_DAILY_CALLS = 5000;

    public const MAX_DAILY_TOKENS = 10000000;

    public function __construct(private AdminAudit $audit) {}

    /** @param list<string> $tools */
    public function handle(User $actor, bool $enabled, array $tools, int $dailyCalls, ?int $dailyTokens = null): void
    {
        $unknown = array_diff($tools, array_keys(AssistantAccess::TOOLS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['tools' => 'Hay herramientas que no existen.']);
        }
        if ($dailyCalls < 1 || $dailyCalls > self::MAX_DAILY_CALLS) {
            throw ValidationException::withMessages(['dailyCalls' => 'Elige un límite entre 1 y '.self::MAX_DAILY_CALLS.' consultas al día.']);
        }

        if ($dailyTokens !== null && ($dailyTokens < 1000 || $dailyTokens > self::MAX_DAILY_TOKENS)) {
            throw ValidationException::withMessages(['dailyTokens' => 'Elige un límite de uso válido.']);
        }

        DB::transaction(function () use ($actor, $enabled, $tools, $dailyCalls, $dailyTokens): void {
            [$actor] = LockedUsers::pair($actor->id, $actor->id);
            Gate::forUser($actor)->authorize('manage-assistant');
            $settings = AssistantSetting::current();
            $settings->enabled = $enabled;
            $settings->tools = array_values(array_intersect(array_keys(AssistantAccess::TOOLS), $tools));
            $settings->daily_calls = $dailyCalls;
            if ($dailyTokens !== null) {
                $settings->daily_tokens = $dailyTokens;
            }
            if (! $settings->isDirty()) {
                return;
            }
            $settings->save();
            $this->audit->recordAssistant($actor, $actor, 'assistant.updated');
        });
    }
}
