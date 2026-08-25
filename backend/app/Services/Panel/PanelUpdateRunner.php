<?php

namespace App\Services\Panel;

use App\Enums\PanelUpdateStatus;
use App\Models\PanelUpdate;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;

/**
 * Starts the update and reads back what it did.
 *
 * The runner is launched detached — `setsid` puts it in its own session, so
 * reloading php-fpm and restarting the queue partway through does not take it
 * with them. Nothing waits on it: the request that started the update returns
 * immediately, and progress is read from the state file afterwards.
 *
 * That is also why the row is never advanced by the process that started it.
 * The only source of truth for a finished update is the file the script wrote,
 * because by then the PHP process that dispatched it is gone.
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

        // Legacy or release — asked of the layout. See UpdateFlow.
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

        // Detached and fully redirected. Without closing all three streams the
        // child keeps the php-fpm request's pipes open and the response never
        // finishes flushing.
        $log = $dir.'/update-'.$update->getKey().'.log';

        $this->launch($path, $log, (string) $update->getKey());

        return true;
    }

    /**
     * Start the script somewhere it will survive the update restarting the
     * services the panel runs under.
     *
     * `setsid` alone is not enough, and neither is `nohup`. Both deal with
     * signals — a new session has no controlling terminal, so SIGHUP is never
     * delivered — but this script is started from an HTTP request and so
     * inherits the panel's **php-fpm cgroup**. systemd's default
     * `KillMode=control-group` kills every process in a unit's cgroup when it
     * restarts, whatever session or process group they are in. A transient
     * unit is the only thing that actually escapes that.
     *
     * It matters because the update restarts the panel's own services partway
     * through, and the run has to outlive them: the state file it writes is
     * the only thing the progress screen has to read, so a script killed
     * mid-step leaves the UI polling a run that will never advance again.
     *
     * Falls back to the old `setsid` form when systemd-run is unavailable or
     * not permitted — a server whose sudoers predates this must keep updating,
     * and today's behaviour is what it gets.
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
     * The script in a transient systemd unit of its own.
     *
     * Built separately from running it so the shape of the command is
     * testable: `start()` shells out for real, so nothing else here can be
     * exercised without spawning units on the machine running the suite.
     */
    public static function transientCommand(string $path, string $log, string $key): string
    {
        return sprintf(
            'sudo -n systemd-run --collect --quiet --unit=%s /bin/sh -c %s',
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
     * Bring the row in line with the state file.
     *
     * Called whenever an update is read. This is what makes a success that
     * completed *after* the panel restarted show up as a success rather than
     * a row stuck at `running` forever.
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

            // This is the only place the outcome of a run is ever recorded
            // anywhere other than the row itself. The script that produced it
            // runs detached, outside any request — by the time anyone is here
            // to read the state file, the original request that started the
            // update is long gone, so this is the sole opportunity to put the
            // result somewhere a person can find it without knowing to query
            // the panel_updates table directly.
            $this->activity->log('panel_update.succeeded', $update, [
                'from_version' => $update->from_version,
                'to_version' => $update->to_version,
            ], actor: $update->user);
        }

        if ($state['status'] === 'failed') {
            $attributes['status'] = PanelUpdateStatus::Failed;
            $attributes['finished_at'] = now();
            // The step that failed is the reason code — a stable key the UI
            // can translate, never raw stderr.
            $attributes['reason'] = (string) ($state['reason'] ?: 'unknown');
            // New scripts say whether anything had to be restored. Older
            // state files predate the field and only wrote failures after the
            // rollback trap, so true is the compatible fallback.
            $attributes['rolled_back'] = (bool) ($state['rolled_back'] ?? true);

            $this->activity->log('panel_update.failed', $update, [
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
