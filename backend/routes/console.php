<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every `withoutOverlapping()` below names its lock expiry in minutes. The default is 24 hours,
// so a killed PHP process would block the command for a day. Each expiry is well above the
// command's run time. (Reboots cannot strand a lock: see BootScopedEventMutex.)

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Dashboard metrics: sampled every 5 minutes for the 24h charts; the command prunes old rows.
Schedule::command('server:sample-metrics')->everyFiveMinutes()->withoutOverlapping(10);

// Per-engine DB metrics for the Query Monitor chart (same model).
Schedule::command('db:sample-metrics')->everyFiveMinutes()->withoutOverlapping(10);

// The databases list reads a stored size; this keeps it current. Ten minutes is fine for a list,
// and `show` re-measures on demand.
Schedule::command('databases:refresh-sizes')->everyTenMinutes()->withoutOverlapping(30);

// Automatic disk cleaner: the tick wakes the command every minute and it self-gates on the DB
// schedule (enabled / due / threshold). No cron file, so it cannot drift from user Cronjobs.
Schedule::command('disk-cleaner:run')->everyMinute()->withoutOverlapping(60);

// Support and end-of-life dates for Node and PHP. Daily is plenty; the point is that the API
// never makes a network call inside a request. With no egress the cache stays empty (no badges).
Schedule::command('runtimes:refresh-lifecycle')->daily()->withoutOverlapping(120);

// The newest npm of each npm major, and the Node versions each one runs on. Same reasoning as
// the lifecycle refresh; daily because npm ships less often than that.
Schedule::command('runtimes:refresh-npm')->daily()->withoutOverlapping(120);

// The Node range a one-click application accepts, read from the release the installer would
// fetch. Stored so the create form never blocks on the registry. Daily: `engines.node` changes
// a few times a year, and the picker must follow it.
Schedule::command('runtimes:refresh-app-packages')->daily()->withoutOverlapping(120);

// Renewal happens outside the panel (certbot's own timer), so without this the SSL screen
// counts down from the issuance date and reports "expired" after a successful renewal.
// Daily, because the number is measured in days.
Schedule::command('certificates:refresh-expiry')->daily()->withoutOverlapping(120);

// Backups: the tick wakes the command every minute and it self-gates on each target's DB
// schedule. No cron file, so it cannot drift from user Cronjobs.
Schedule::command('backups:run-due')->everyMinute()->withoutOverlapping(10);

// Site disk usage on the applications list, at most a minute old by default.
//
// Its cost scales with the customer's data (`du` walks every inode and evicts the page cache),
// so on a server with many large sites a pass may not finish within the minute. Tune it with
// `server.application_size.per_run` (sites per tick) and `stale_minutes` (skip recent ones);
// sites are taken least-recently-measured first. Deploys, installs and file edits measure
// immediately regardless.
Schedule::command(sprintf(
    'applications:measure-sizes --stale=%d --limit=%d',
    (int) config('server.application_size.stale_minutes', 0),
    (int) config('server.application_size.per_run', 0),
))->everyMinute()->withoutOverlapping(120);

// Joomla and Nextcloud loosen their own config file (Joomla to 0444) whenever settings are
// saved, and resync only runs on deploy. Five minutes bounds how long the web server can read it.
Schedule::command('sites:narrow-app-secrets')->everyFiveMinutes()->withoutOverlapping(10);

// File manager trash. Every delete keeps a full copy, so without a sweep this leaks disk space.
// Daily because retention is measured in days.
Schedule::command('files:prune-trash')->daily()->withoutOverlapping(120);

// The activity log's "keep for" choice. A no-op while it is "keep everything" (the default).
Schedule::command('activity:prune')->daily()->withoutOverlapping(120);

// Unfinished uploads: an interrupted upload leaves a part file behind and uploads have no size
// limit. The per-chunk free-space guard refuses writes near a full disk; this stops it filling.
Schedule::command('uploads:reap')->daily()->withoutOverlapping(120);

// The site copy a failed restore leaves beside the site (`.rollback-{id}`): a whole second copy.
// Kept a week, then removed only where the site is in place and the restore's safety backup
// still exists (RollbackCopies::pruneFailed).
Schedule::command('restores:prune-rollbacks')->daily()->withoutOverlapping(120);

// A site whose install job died silently (worker killed, disk full) stays `provisioning`, and
// Delete refuses such a site. Past its time budget (StrandedProvisioning) it is marked failed,
// so Retry setup or Delete works.
Schedule::command('applications:close-stranded')->everyFiveMinutes()->withoutOverlapping(10);

// Prepared backup downloads (SFTP/FTP): a whole site plus database copied to the panel's disk,
// promised for an hour. Every five minutes so "an hour" is about that.
Schedule::command('backups:prune-downloads')->everyFiveMinutes()->withoutOverlapping(10);
