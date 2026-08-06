<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Is the panel fighting itself for SQLite's single write lock?
 *
 * SQLite allows exactly one writer. Put the queue on it and the worker polls
 * the same file every request is trying to write; put sessions on it and every
 * authenticated request adds a write. On a single-user panel doing nothing,
 * that is enough to produce "database is locked" — and the error surfaces on
 * whichever unrelated request happened to lose the race, so it never points at
 * the cause.
 *
 * Reported rather than fixed here, because a check must never change the
 * server to find out whether the server works. `panel:configure-services` is
 * what changes it; this is what tells you to run it.
 *
 * Only a warning: a panel in this state is slower and flakier under load, not
 * broken, and calling it a failure would make `panel:doctor` cry wolf on every
 * install that predates the fix.
 */
class DriverContentionCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'driver_contention';
    }

    public function run(): array
    {
        $database = (string) config('database.default');

        if ($database !== 'sqlite') {
            // MySQL and Postgres take concurrent writers. The drivers are then
            // a performance preference, not a contention problem, and there is
            // nothing here worth an opinion.
            return [
                'status' => 'pass',
                'detail' => $database.' database (concurrent writers)',
                'fix' => null,
            ];
        }

        $onDatabase = array_keys(array_filter([
            'queue' => config('queue.default') === 'database',
            'sessions' => config('session.driver') === 'database',
            'cache' => config('cache.default') === 'database',
        ]));

        if ($onDatabase === []) {
            return [
                'status' => 'pass',
                'detail' => 'queue, sessions and cache are off SQLite',
                'fix' => null,
            ];
        }

        $detail = implode(', ', $onDatabase).' on SQLite';

        // Whether the advice is actionable depends on Redis being there — and
        // "move these to Redis" is useless advice on a box without one.
        return [
            'status' => 'warn',
            'detail' => $this->redisAnswers()
                ? $detail.'; Redis is available'
                : $detail.'; Redis did not answer',
            'fix' => $this->redisAnswers()
                ? 'doctor.fixes.drivers_on_sqlite'
                : 'doctor.fixes.drivers_no_redis',
        ];
    }

    private function redisAnswers(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
