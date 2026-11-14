<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('portal:sync-accounts')->everyMinute()->withoutOverlapping();

Schedule::command('portal:uploads-cleanup')->everyTenMinutes()->withoutOverlapping();

Schedule::command('portal:files-purge')->dailyAt('03:30')->withoutOverlapping();

// Long ZIP builds run in the background so other schedules are not delayed.
Schedule::command('portal:exports-run')->everyMinute()->withoutOverlapping()->runInBackground();
