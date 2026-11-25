<?php

namespace App\Services\Panel;

use App\Enums\PanelUpdateStatus;
use App\Models\PanelUpdate;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;

/**
 * Starts the update and reads back what it did.
 *
 * The script runs detached and nothing waits on it: progress is read from its state file, which
 * is the only source of truth for a finished update (the process that started it is long gone).
 */
class PanelUpdateRunner
{
    public function __construct(
        private UpdateScript $script,
        private UpdateFlow $flow,
        private ActivityLogger $activity,
    ) {}

    /**
     * Write the script somewhere the checkout cannot reach and exec it detached.
     */
    public function start(PanelUpdate $update, string $version, bool $dryRun = false): bool
    {
        $dir = (string) config('panel_update.state_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            Log::error('Panel update could not create its state directory.', [
                'feature' => 'panel_update',
                'directory' => $dir,
                'panel_update' => $update->getKey(),
            ]);

            return false;
        }

        // Legacy or release layout: see UpdateFlow.
        $flow = $this->flow->script();
        $path = $this->script->scriptPath($update);

        if (@file_put_contents($path, $flow->render($update, $version, $dryRun)) === false) {
            Log::error('Panel update could not write its runner script.', [
                'feature' => 'panel_update',
                'path' => $path,
                'panel_update' => $update->getKey(),
            ]);

            return false;
        }

        @chmod($path, 0700);

        // Fully redirected: otherwise the child keeps the php-fpm request's pipes open and the
        // response never finishes.
        $log = $dir.'/update-'.$update->getKey().'.log';

        if (config('panel_update.launch', true)) {
            $this->launch($path, $log, (string) $update->getKey());
        }

        return true;
    }

    /**
     * Start the script so it survives the update restarting the panel's own services.
     *
     * `setsid` and `nohup` are not enough: the script inherits the panel's php-fpm cgroup, and
     * systemd's `KillMode=control-group` kills the whole cgroup on restart. Only a transient unit
     * escapes it. A script killed mid-step leaves the UI polling a run that never advances.
     *
     * Falls back to `setsid` when systemd-run is unavailable or not permitted (old sudoers).
     */
    private function launch(string $path, string $log, string $key): void
    {
        $output = [];
        $status = 0;
        exec(self::transientCommand($path, $log, $key).' 2>&1', $output, $status);

        if ($status === 0) {
            return;
        }

        Log::warning('Panel update fell back to setsid; it will not survive a restart of the panel\'s own services.', [
            'feature' => 'panel_update',
            'panel_update' => $key,
            'detail' => implode(' ', $output),
        ]);

        exec(self::fallbackCommand($path, $log));
    }

    /**
     * The script in a transient systemd unit of its own. Built apart from running it so tests
     * can check the command without spawning units.
     */
    public static function transientCommand(string $path, string $log, string $key): string
    {
        // OOMPolicy=continue: by default systemd stops the whole unit when the kernel OOM-kills
        // any process in it. The script must survive a killed frontend build to roll back and
        // record `frontend_build_out_of_memory`.
        return sprintf(
            'sudo -n systemd-run --collect --quiet -p OOMPolicy=continue --unit=%s /bin/sh -c %s',
            escapeshellarg('panel-update-'.$key),
            escapeshellarg(self::redirected($path, $log)),
        );
    }

    /** The pre-systemd form, for a server whose sudoers does not allow the above. */
    public static function fallbackCommand(string $path, string $log): string
    {
        return sprintf('setsid %s < /dev/null &', self::redirected($path, $log));
    }

    private static function redirected(string $path, string $log): string
    {
        return sprintf('%s > %s 2>&1', escapeshellarg($path), escapeshellarg($log));
    }

    /**
     * Bring the row in line with the state file, so a run that finished after the panel
     * restarted does not stay `running` forever.
     */
    public function reconcile(PanelUpdate $update): PanelUpdate
    {
        if (! $update->status->inFlight()) {
            return $update;
        }

        $path = $this->script->statePath($update);

        if (! is_file($path) || ! is_readable($path)) {
            return $update;
        }

        $state = json_decode((string) @file_get_contents($path), true);

        if (! is_array($state) || ! isset($state['status'], $state['step'])) {
            return $update;
        }

        $attributes = ['current_step' => (string) $state['step']];

        if ($state['status'] === 'running') {
            $attributes['status'] = PanelUpdateStatus::Running;
        }

        if ($state['status'] === 'succeeded') {
            $attributes['status'] = PanelUpdateStatus::Succeeded;
            $attributes['finished_at'] = now();
            $attributes['to_commit'] = $state['commit'] ?? null;

            // The only place a run's outcome reaches the activity log: the script runs detached,
            // outside any request.
            $this->activity->log($update->dry_run ? 'panel_update.dry_run_succeeded' : 'panel_update.succeeded', $update, [
                'from_version' => $update->from_version,
                'to_version' => $update->to_version,
            ], actor: $update->user);
        }

        if ($state['status'] === 'failed') {
            $attributes['status'] = PanelUpdateStatus::Failed;
            $attributes['finished_at'] = now();
            // The failed step is the reason code: a stable key the UI translates, never raw stderr.
            $attributes['reason'] = (string) ($state['reason'] ?: 'unknown');
            // Older state files lack the field and only wrote failures after the rollback trap,
            // so true is the compatible fallback.
            $attributes['rolled_back'] = (bool) ($state['rolled_back'] ?? true);

            $this->activity->log($update->dry_run ? 'panel_update.dry_run_failed' : 'panel_update.failed', $update, [
                'from_version' => $update->from_version,
                'to_version' => $update->to_version,
                'reason' => $attributes['reason'],
                'rolled_back' => $attributes['rolled_back'],
            ], actor: $update->user);
        }

        $update->update($attributes);

        return $update->refresh();
    }
}
