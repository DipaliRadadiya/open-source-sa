<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is the queue actually draining?
 *
 * The services check proves the worker unit is *running*, which is not the
 * same thing. A worker booted before a migration, holding a stale cached
 * config, or crash-looping fast enough to look active will sit there while
 * jobs pile up — and every route that queues work keeps returning 202. The
 * panel looks fine and nothing happens: provisioning never finishes, deploys
 * never run, installs never complete.
 *
 * Backlog alone is not a fault — a busy panel has jobs waiting. Age is the
 * signal: a job that has been available for minutes is one nobody is taking.
 */
class QueueCheck implements DoctorCheck
{
    /** Older than this and no plausible worker is keeping up. */
    private const STALE_SECONDS = 300;

    public function key(): string
    {
        return 'queue';
    }

    public function run(): array
    {
        if (config('queue.default') !== 'database') {
            // Redis and others keep their state elsewhere; the services check
            // covers the worker, and guessing here would be worse than saying
            // nothing.
            return [
                'status' => 'pass',
                'detail' => config('queue.default').' driver (backlog not inspected)',
                'fix' => null,
            ];
        }

        try {
            $table = (string) config('queue.connections.database.table', 'jobs');

            $pending = DB::table($table)->count();
            $oldest = DB::table($table)->min('available_at');
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'detail' => 'cannot read the queue tables',
                'fix' => 'doctor.fixes.queue_unreadable',
            ];
        }

        $waited = $oldest === null ? 0 : max(0, time() - (int) $oldest);

        if ($waited > self::STALE_SECONDS) {
            return [
                'status' => 'fail',
                'detail' => $pending.' job(s) waiting, oldest for '.round($waited / 60).' min',
                'fix' => 'doctor.fixes.queue_stalled',
            ];
        }

        if ($failed > 0) {
            // Not a failure of the queue itself — the worker is doing its job.
            // But silently discarded work is why a feature "did nothing", so
            // it has to be visible somewhere.
            return [
                'status' => 'warn',
                'detail' => $failed.' failed job(s); '.$pending.' waiting',
                'fix' => 'doctor.fixes.queue_failed_jobs',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $pending.' waiting, none stale',
            'fix' => null,
        ];
    }
}
