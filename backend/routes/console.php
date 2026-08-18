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

// Backups: the tick just wakes the command every minute; the command
// self-gates on each target's DB schedule. No cron file, so it can never
// drift with the user-managed Cronjobs feature.
Schedule::command('backups:run-due')->everyMinute()->withoutOverlapping();

// Site disk usage, kept current for the sites list.
//
// Every minute, but never `--all`: `du` walks every inode, and one pass over
// this project's own eight test sites is already ~6s of solid disk. Unbounded,
// a server with forty real sites would still be walking when the next tick
// fired, permanently, and would evict the page cache the sites themselves are
// served from — paying in site performance for a number nobody is watching
// second by second.
//
// So each tick takes the few least-recently-measured sites past the staleness
// threshold. Every site still comes round, the work per tick is bounded no
// matter how many sites exist, and the cost stops scaling with the customer's
// disk. Deploys, installs and file edits still measure immediately; this only
// catches drift from what the applications write themselves.
Schedule::command(sprintf(
    'applications:measure-sizes --stale=%d --limit=%d',
    (int) config('server.application_size.stale_minutes', 60),
    (int) config('server.application_size.per_run', 5),
))->everyMinute()->withoutOverlapping();

// File manager trash. Every delete keeps a full copy, so without a sweep this
// is a slow disk-space leak on a machine whose whole job is running out of
// disk quietly. Daily because the unit is days — and scheduled at all because
// the retention was written first and wired up second, which for a while meant
// the API promised a sweep that never ran.
Schedule::command('files:prune-trash')->daily()->withoutOverlapping();
