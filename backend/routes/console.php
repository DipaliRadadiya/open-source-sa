<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Server dashboard metrics: sample every 5 minutes into server_metrics for the
// 24h charts; the command prunes rows past the retention window (bounded table).
Schedule::command('server:sample-metrics')->everyFiveMinutes()->withoutOverlapping();

// Per-engine DB metrics for the Query Monitor chart (same bounded-table model).
Schedule::command('db:sample-metrics')->everyFiveMinutes()->withoutOverlapping();

// Automatic disk cleaner: the tick just wakes the command every minute; the
// command self-gates on the DB schedule (enabled / due / threshold). No cron
// file, so it can never drift with the user-managed Cronjobs feature.
Schedule::command('disk-cleaner:run')->everyMinute()->withoutOverlapping();

// Support and end-of-life dates for Node and PHP, from each project's own
// published schedule. Daily is far more often than these change; the point is
// that the API never makes a network call inside a request. A box with no
// egress simply keeps an empty cache and shows no badges, which is honest.
Schedule::command('runtimes:refresh-lifecycle')->daily()->withoutOverlapping();
