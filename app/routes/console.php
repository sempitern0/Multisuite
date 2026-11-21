<?php

use App\Models\AssistantToolCall;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('portal:sync-accounts')->everyMinute()->withoutOverlapping();

// Connected folders: changes sync at once; this pass repairs anything a failure left behind.
Schedule::command('portal:media-sync')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();

Schedule::command('portal:uploads-cleanup')->everyTenMinutes()->withoutOverlapping();

Schedule::command('portal:files-purge')->dailyAt('03:30')->withoutOverlapping();

// Long ZIP builds run in the background so other schedules are not delayed.
Schedule::command('portal:exports-run')->everyMinute()->withoutOverlapping()->runInBackground();
// Assistant audit rows older than 90 days (docs/MCP.md).
Schedule::command('model:prune', ['--model' => [AssistantToolCall::class]])->dailyAt('04:10')->withoutOverlapping();
// Assistant conversations untouched for 30 days (decision D4).
Schedule::command('portal:assistant-prune')->dailyAt('04:20')->withoutOverlapping();
// Search by meaning: new, changed and removed files of people who accepted it (MCP-07).
Schedule::command('portal:assistant-index')->everyFifteenMinutes()->withoutOverlapping(30);
