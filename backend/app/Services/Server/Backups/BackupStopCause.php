<?php

namespace App\Services\Server\Backups;

use App\Models\Backup;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The most likely reason a backup died without reporting back.
 *
 * A run killed outright (a reboot, a restarted worker, the job's own time
 * limit) cannot say why, so the row only ever read "never reported back"
 * and support had nothing to tell the user (2026-10-29, Dipali). Two things
 * on the box outlive the run and can be compared with when it started: the
 * boot time and when the queue worker last started. Either one being later
 * than the run's start is the answer. Neither is a guess dressed up: when
 * both are older, the cause is left unknown and the message says so.
 *
 * Out-of-memory is not detected: the kernel log needs root, and naming it
 * without evidence would send a user to add memory for nothing.
 */
class BackupStopCause
{
    public const TIMED_OUT = 'timed_out';

    public const SERVER_RESTARTED = 'server_restarted';

    public const WORKER_RESTARTED = 'worker_restarted';

    public function for(Backup $backup, ?Throwable $e = null): ?string
    {
        if ($e instanceof TimeoutExceededException) {
            return self::TIMED_OUT;
        }

        $started = $backup->started_at ?? $backup->created_at;

        if ($started === null) {
            return null;
        }

        $booted = $this->bootedAt();

        if ($booted !== null && $booted->greaterThan($started)) {
            return self::SERVER_RESTARTED;
        }

        $worker = $this->workerStartedAt();

        if ($worker !== null && $worker->greaterThan($started)) {
            return self::WORKER_RESTARTED;
        }

        return null;
    }

    /** `btime` in /proc/stat: seconds since the epoch, readable by anyone. */
    protected function bootedAt(): ?Carbon
    {
        $stat = @file_get_contents((string) config('server.backups.boot_stat_path', '/proc/stat'));

        if ($stat === false || preg_match('/^btime\s+(\d+)$/m', $stat, $match) !== 1) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $match[1]);
    }

    /** When systemd last started the queue worker; no sudo needed to ask. */
    protected function workerStartedAt(): ?Carbon
    {
        $service = (string) config('panel_update.services.queue', 'panel-queue.service');

        try {
            $result = Process::timeout(10)->run(['systemctl', 'show', $service, '-p', 'ActiveEnterTimestamp', '--value']);
        } catch (Throwable) {
            return null;
        }

        $value = trim($result->output());

        if (! $result->successful() || $value === '' || $value === 'n/a') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
