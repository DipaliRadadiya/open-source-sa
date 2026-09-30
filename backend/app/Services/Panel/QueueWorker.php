<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The panel's one queue worker, and which queues it drains.
 *
 * There is exactly one worker, on purpose: two workers changing the same
 * server at once is a failure mode, not throughput. So every job waits behind
 * every other one — and a certificate a site is waiting on sat behind sixteen
 * queued installs for 22 minutes, while the panel called the site active.
 *
 * The worker takes `high` before `default`. That keeps one job at a time and
 * moves short, user-facing work to the front of the line: a certificate now
 * waits for the job that is running, not for every job queued.
 *
 * **A job sent to a queue no worker reads is never run** — no error, no
 * failed_jobs row. Backups shipped that way once. The unit on an existing
 * server is only rewritten by `panel:queue-worker`, so a job goes to `high`
 * only when the *running* worker is seen to read it; anything else, including
 * not being able to tell, is the default queue, which every worker reads.
 */
class QueueWorker
{
    public const PRIORITY = 'high';

    /** The `--queue` list the unit is written with. Order is priority. */
    public const QUEUES = 'high,default';

    /**
     * The queue for a job a user is waiting on: `high` when the running worker
     * reads it, otherwise null (the connection's default).
     */
    public function priorityQueue(): ?string
    {
        return in_array(self::PRIORITY, $this->runningQueues(), true) ? self::PRIORITY : null;
    }

    /**
     * The queues the running worker was started with. Empty when there is no
     * worker, it could not be read, or it names none (default only).
     *
     * @return array<int, string>
     */
    public function runningQueues(): array
    {
        try {
            $pid = trim(Process::timeout(5)->run(['systemctl', 'show', $this->service(), '-p', 'MainPID', '--value'])->output());

            if (preg_match('/^[1-9]\d*$/', $pid) !== 1) {
                return [];
            }

            $args = Process::timeout(5)->run(['ps', '-o', 'args=', '-p', $pid]);

            return $args->successful() ? self::queuesIn($args->output()) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public static function queuesIn(string $commandLine): array
    {
        if (! str_contains($commandLine, 'queue:work')
            || preg_match('/(?:^|\s)--queue(?:=|\s+)(\S+)/', $commandLine, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $match[1]))));
    }

    /**
     * The unit with `--queue=high,default` added to its `queue:work` line.
     * Null when there is nothing to change: already reads `high`, or has a
     * `--queue` list of its own, which is somebody's decision and left alone.
     */
    public static function withPriority(string $unit): ?string
    {
        $changed = false;

        $lines = array_map(function (string $line) use (&$changed): string {
            if (! str_starts_with(ltrim($line), 'ExecStart=') || ! str_contains($line, 'queue:work')
                || preg_match('/(?:^|\s)--queue(?:=|\s)/', $line) === 1) {
                return $line;
            }

            $changed = true;

            return (string) preg_replace('/queue:work(?=\s|$)/', 'queue:work --queue='.self::QUEUES, $line, 1);
        }, explode("\n", $unit));

        return $changed ? implode("\n", $lines) : null;
    }

    public function service(): string
    {
        return (string) config('panel_update.services.queue', 'panel-queue.service');
    }

    public function unitPath(): string
    {
        return rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/').'/'.$this->service();
    }
}
