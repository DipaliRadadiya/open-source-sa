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

// The databases list reads a stored size rather than querying every schema on
// every request. That is only the right trade if something keeps the column
// current — without this it reported the size at creation, which is zero,
// forever. Ten minutes is well inside "a few minutes old is fine" for a list,
// and `show` re-measures on demand for anyone who wants the exact figure.
Schedule::command('databases:refresh-sizes')->everyTenMinutes()->withoutOverlapping();

// Automatic disk cleaner: the tick just wakes the command every minute; the
// command self-gates on the DB schedule (enabled / due / threshold). No cron
// file, so it can never drift with the user-managed Cronjobs feature.
Schedule::command('disk-cleaner:run')->everyMinute()->withoutOverlapping();

// Support and end-of-life dates for Node and PHP, from each project's own
// published schedule. Daily is far more often than these change; the point is
// that the API never makes a network call inside a request. A box with no
// egress simply keeps an empty cache and shows no badges, which is honest.
Schedule::command('runtimes:refresh-lifecycle')->daily()->withoutOverlapping();

// Renewal happens outside the panel — certbot's own timer swaps the file every
// sixty days and tells nothing. Without this the SSL screen counts down from
// the date captured at issuance and eventually reports "expired" on a site
// whose certificate renewed correctly weeks ago. Daily, because the number it
// maintains is measured in days.
Schedule::command('certificates:refresh-expiry')->daily()->withoutOverlapping();
