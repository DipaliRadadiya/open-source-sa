<?php

namespace App\Console\Commands;

use App\Services\Panel\QueueWorker;
use App\Services\Panel\QueueWorkerUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Reconcile supported existing worker units: priority queues and graceful stop.
 *
 * mixed signals only the main PHP daemon first; Laravel drains its synchronous
 * job before exiting. The finite deadline/final KILL still contain all children.
 * Requires the actual interpreter's pcntl support before accepting any job.
 *
 * Runs as root from the updater/manual deploy. Never restarts the worker here;
 * the next service restart applies the unit (including its new stop policy).
 * Custom lifecycle/overriding drop-ins are refused without changing them.
 */
class PanelQueueWorker extends Command
{
    protected $signature = 'panel:queue-worker
        {--dry-run : Report whether the unit is current, without writing}';

    protected $description = 'Configure priority queues and contained graceful worker stops';

    public function handle(QueueWorker $worker): int
    {
        $path = $worker->unitPath();

        if (! is_file($path)) {
            // A vendor/runtime or removed-but-still-loaded unit may exist.
            // Success here would authorize callers to stop an unverified one.
            $this->error(__('panel_update.reasons.configure_queue_worker').' [queue_unit_primary_unavailable]');

            return self::FAILURE;
        }

        try {
            if (is_link($path)) {
                throw new RuntimeException('queue_unit_symlink_unsupported');
            }
            $current = file_get_contents($path);
            if ($current === false) {
                throw new RuntimeException('queue_unit_unreadable');
            }
            $desired = QueueWorkerUnit::reconcile($current);
            $desired = QueueWorker::withPriority($desired) ?? $desired;
            $metadata = $this->inspectOverrides($worker, $path);
            if ($desired === $current) {
                QueueWorkerUnit::verifyEffective($metadata, $desired);
            }
        } catch (Throwable $e) {
            $this->error(__('panel_update.reasons.configure_queue_worker').' ['.$e->getMessage().']');

            return self::FAILURE;
        }

        if ($desired === $current) {
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line(__('panel_update.steps.configure_queue_worker').": {$path} (dry-run; KillMode=mixed)");

            return self::SUCCESS;
        }

        // Preserve operator ownership/mode and all unrelated directives. A
        // changed file since inspection is a conflict, never permission to
        // overwrite somebody else's concurrent edit.
        if (file_get_contents($path) !== $current || ! $this->writeUnit($path, $desired)) {
            $this->error(__('panel_update.reasons.configure_queue_worker').' [queue_unit_write_failed]');

            return self::FAILURE;
        }

        try {
            $reload = Process::timeout(30)->run(['systemctl', 'daemon-reload']);
            if ($reload->failed()) {
                throw new RuntimeException('queue_unit_reload_failed');
            }
            // Discovery was loaded before the write. A drop-in can appear
            // during reload; verify discovery AND actual effective policy.
            QueueWorkerUnit::verifyEffective($this->inspectOverrides($worker, $path), $desired);
        } catch (Throwable) {
            // Restore only OUR bytes, never an operator's concurrent edit.
            // A failed reload/verification must not look like a current unit.
            $restored = file_get_contents($path) === $desired && $this->writeUnit($path, $current);
            if ($restored) {
                try {
                    Process::timeout(30)->run(['systemctl', 'daemon-reload']);
                } catch (Throwable) {
                    // Failure stays visible; no stop/restart is attempted.
                }
            }
            $this->error(__('panel_update.reasons.configure_queue_worker').' [queue_unit_reload_failed; restored='.(int) $restored.']');

            return self::FAILURE;
        }

        $this->info(__('panel_update.steps.configure_queue_worker').": {$path} [KillMode=mixed; queues=".implode(',', QueueWorker::queuesIn($desired)).']');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function inspectOverrides(QueueWorker $worker, string $path): array
    {
        // systemd discovers global/type/template/runtime drop-ins, not just
        // /etc/<unit>.d. Loaded discovery can be stale: fail closed until the
        // operator has reviewed/reloaded pending changes, never guess at them.
        $result = Process::timeout(5)->run([
            'systemctl', 'show', $worker->service(), '--property=FragmentPath', '--property=DropInPaths',
            '--property=NeedDaemonReload', '--property=MainPID', '--property=KillMode', '--property=KillSignal',
            '--property=RestartKillSignal', '--property=SendSIGHUP', '--property=SendSIGKILL',
            '--property=FinalKillSignal', '--property=TimeoutStopUSec', '--property=TimeoutStopFailureMode',
        ]);
        preg_match_all('/^([A-Za-z]+)=(.*)$/m', $result->output(), $properties, PREG_SET_ORDER);
        $metadata = [];
        foreach ($properties as $property) {
            if (isset($metadata[$property[1]])) {
                throw new RuntimeException('queue_unit_duplicate_metadata');
            }
            $metadata[$property[1]] = $property[2];
        }
        if ($result->failed() || ($metadata['FragmentPath'] ?? null) !== $path
            || ! isset($metadata['DropInPaths']) || ($metadata['NeedDaemonReload'] ?? null) !== 'no') {
            throw new RuntimeException('queue_unit_effective_metadata_unavailable');
        }
        $pid = $metadata['MainPID'] ?? '';
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $pid) !== 1) {
            throw new RuntimeException('queue_unit_main_pid_unreadable');
        }
        if ($pid !== '0') {
            // A new ExecStartPre protects the NEXT worker, not one already
            // accepting jobs without pcntl. Refuse that unsafe first restart.
            $status = Process::timeout(5)->run(['cat', '/proc/'.$pid.'/status']);
            if ($status->failed() || preg_match('/^SigCgt:\s*([0-9a-fA-F]{16})$/m', $status->output(), $caught) !== 1
                || ((int) hexdec(substr($caught[1], -8)) & 0x4006) !== 0x4006) {
                // Linux TERM15/QUIT3/INT2: all installed Laravel drain handlers.
                throw new RuntimeException('queue_unit_running_worker_not_signal_aware');
            }
        }
        foreach (preg_split('/\s+/', trim($metadata['DropInPaths']), flags: PREG_SPLIT_NO_EMPTY) as $dropIn) {
            if (preg_match('#^/[A-Za-z0-9._@/:-]+$#D', $dropIn) !== 1 || ! is_readable($dropIn)) {
                throw new RuntimeException('queue_unit_dropin_unreadable');
            }
            $contents = file_get_contents($dropIn);
            if ($contents === false) {
                throw new RuntimeException('queue_unit_dropin_unreadable');
            }
            QueueWorkerUnit::assertSafeOverrides($contents);
        }

        return $metadata;
    }

    private function writeUnit(string $path, string $contents): bool
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        // Exclusive/random temporary storage; never reuse somebody's partial
        // file. Preservation covers ordinary permission bits/uid/gid only.
        if ($stat === false || ($stat['mode'] & 07000) !== 0) {
            return false;
        }
        $temporary = tempnam(dirname($path), '.panel-queue-');
        if ($temporary === false) {
            return false;
        }
        if (dirname($temporary) !== dirname($path)) {
            @unlink($temporary);

            return false;
        }
        $written = file_put_contents($temporary, $contents) === strlen($contents)
            && chmod($temporary, $stat['mode'] & 0777)
            && (fileowner($temporary) === $stat['uid'] || chown($temporary, $stat['uid']))
            && (filegroup($temporary) === $stat['gid'] || chgrp($temporary, $stat['gid']))
            && rename($temporary, $path);
        if (! $written) {
            @unlink($temporary);
        }

        return $written;
    }
}
