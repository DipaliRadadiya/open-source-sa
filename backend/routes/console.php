<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Automatic disk cleaner: the tick just wakes the command every minute; the
// command self-gates on the DB schedule (enabled / due / threshold). No cron
// file, so it can never drift with the user-managed Cronjobs feature.
Schedule::command('disk-cleaner:run')->everyMinute()->withoutOverlapping();
