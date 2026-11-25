<?php

namespace App\Services\Server;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;

/**
 * Manages cron jobs as one file per job under /etc/cron.d (the same convention
 * as the legacy panel). Non-destructive: we only ever touch our own files, so a
 * user's personal crontab and other tools' jobs are never affected. Each file
 * carries the 6-field format (`min hour dom mon dow USER command`), so the job
 * runs as the target OS user — managed System User or a default account.
 */
class CrontabManager
{
    /**
     * `source_path` of a job adopted from a user crontab: `crontab:<user>`.
     * The line itself is in `source_line`.
     */
    public const CRONTAB_SOURCE = 'crontab:';

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Whether the cron daemon is running, or null when that could not be told.
     *
     * When it is not, no job runs (including the panel's scheduler) while every job still shows a
     * next run.
     */
    public function serviceRunning(): ?bool
    {
        // is-active answers 0 for active and 3 for inactive/failed; both are
        // answers, not errors.
        $state = trim($this->serverOps->run(
            ['systemctl', 'is-active', (string) config('server.cron_unit', 'cron')],
            ['feature' => 'cronjob', 'op' => 'service_state'],
            timeout: 15,
            expectedExitCodes: [3],
        )->output());

        return match ($state) {
            'active', 'reloading', 'activating' => true,
            'inactive', 'failed', 'deactivating' => false,
            default => null,
        };
    }

    /**
     * Absolute path of the managed cron.d file for a job. The basename is the
     * job's stored slug (easy to identify with `ls`, stable across data
     * migration) and is run-parts-safe ([a-z0-9-], no dots) or cron ignores it.
     */
    public function path(Cronjob $cronjob): string
    {
        return $this->pathForSlug($cronjob->slug);
    }

    /**
     * Build the cron.d path from an explicit slug (to locate the old file when a job is renamed).
     */
    public function pathForSlug(string $slug): string
    {
        return rtrim(config('server.cron_d'), '/')."/{$slug}";
    }

    /**
     * Remove a specific cron.d file by path (idempotent — `rm -f`).
     */
    public function removePath(string $path): ServerOpsResult
    {
        return $this->serverOps->run(
            ['rm', '-f', $path],
            ['feature' => 'cronjob', 'op' => 'remove_stale', 'path' => $path],
        );
    }

    /**
     * Remove the file an adopted job was imported from, once the panel has written its own.
     *
     * Server Sync names an adopted job by the slug of its command, so `path()` is not where the
     * original was; without this the command would run twice. The source path is stored for that.
     *
     * Returns null when there is nothing to detach, or when the source is the file we manage
     * anyway (removing it would delete the job we just wrote).
     */
    public function detachSource(Cronjob $cronjob): ?ServerOpsResult
    {
        $source = (string) $cronjob->source_path;

        if ($source === '' || $source === $this->path($cronjob)) {
            return null;
        }

        if (str_starts_with($source, self::CRONTAB_SOURCE)) {
            return $this->detachCrontabLine(
                substr($source, strlen(self::CRONTAB_SOURCE)),
                (string) $cronjob->source_line,
                $cronjob,
            );
        }

        return $this->serverOps->run(
            ['rm', '-f', $source],
            ['feature' => 'cronjob', 'op' => 'detach_source', 'cronjob' => $cronjob->id, 'path' => $source],
        );
    }

    /**
     * Take an adopted job's line out of the user crontab it was read from.
     *
     * Only that line, matched exactly, first match only: the rest of the crontab is the user's own
     * and is written back unchanged. A missing line or crontab leaves nothing to do.
     */
    private function detachCrontabLine(string $username, string $line, Cronjob $cronjob): ?ServerOpsResult
    {
        $line = trim($line);

        if ($username === '' || $line === '') {
            return null;
        }

        $context = ['feature' => 'cronjob', 'op' => 'detach_crontab_line', 'cronjob' => $cronjob->id, 'system_user' => $username];

        $current = $this->serverOps->run(['crontab', '-l', '-u', $username], $context);

        // "no crontab for <user>": the line is already gone.
        if ($current->failed()) {
            return str_contains($current->errorOutput(), 'no crontab') ? null : $current;
        }

        $lines = preg_split('/\r?\n/', rtrim($current->output(), "\n")) ?: [];
        $index = null;

        foreach ($lines as $i => $candidate) {
            if (trim($candidate) === $line) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        unset($lines[$index]);

        $remaining = implode("\n", $lines);

        return $this->serverOps->run(
            ['crontab', '-u', $username, '-'],
            $context,
            input: $remaining === '' ? '' : $remaining."\n",
        );
    }

    /**
     * True when the given OS account exists on this server (getent passwd).
     */
    public function userExists(string $username): bool
    {
        return $this->serverOps->run(
            ['getent', 'passwd', $username],
            ['feature' => 'cronjob', 'op' => 'user_check', 'username' => $username],
        )->ok;
    }

    /**
     * Absolute path of the job's output log, derived from the same slug as the cron.d file.
     */
    public function logPath(Cronjob $cronjob): string
    {
        return $this->logPathForSlug($cronjob->slug);
    }

    public function logPathForSlug(string $slug): string
    {
        return rtrim((string) config('server.cronjob_log_dir'), '/')."/{$slug}.log";
    }

    /**
     * Create the log file up front, owned by the account the job runs as.
     *
     * Cron's `>>` would create it as whichever user ran first, in a directory they may not be able
     * to write to; creating it here lets the first run write and keeps jobs off each other's logs.
     */
    private function prepareLog(Cronjob $cronjob): void
    {
        $dir = rtrim((string) config('server.cronjob_log_dir'), '/');
        $log = $this->logPath($cronjob);

        $this->attempt('log_dir', $this->serverOps->run(
            ['mkdir', '-p', $dir],
            ['feature' => 'cronjob', 'op' => 'log_dir', 'cronjob' => $cronjob->id],
        ));

        $this->attempt('log_touch', $this->serverOps->run(
            ['touch', $log],
            ['feature' => 'cronjob', 'op' => 'log_touch', 'cronjob' => $cronjob->id],
        ));

        // The user only, not `user:user`: any account `getent` resolves is accepted, and e.g.
        // `nobody`'s group on Debian is `nogroup`, so `chown nobody:nobody` would fail the creation.
        $this->attempt('log_chown', $this->serverOps->run(
            ['chown', $cronjob->username, $log],
            ['feature' => 'cronjob', 'op' => 'log_chown', 'cronjob' => $cronjob->id],
        ));

        // A job's output can contain anything: keep it off other accounts.
        $this->attempt('log_chmod', $this->serverOps->run(
            ['chmod', '0640', $log],
            ['feature' => 'cronjob', 'op' => 'log_chmod', 'cronjob' => $cronjob->id],
        ));
    }

    /**
     * Install the logrotate policy for cron job logs (idempotent, rewritten with each job write).
     *
     * Unbounded output capture would eventually fill the disk of the server running the sites.
     */
    private function ensureRotation(): ServerOpsResult
    {
        $dir = rtrim((string) config('server.cronjob_log_dir'), '/');
        $days = (int) config('server.cronjob_log_keep_days', 14);
        $size = (string) config('server.cronjob_log_max_size', '10M');
        $path = (string) config('server.cronjob_logrotate_file');

        // copytruncate: the running job holds the file open; rotating by rename would leave it
        // writing to an unseen file.
        $policy = <<<CONF
            # Managed by the control panel — cron job output logs; do not edit by hand
            {$dir}/*.log {
                daily
                rotate {$days}
                maxsize {$size}
                missingok
                notifempty
                compress
                delaycompress
                copytruncate
            }

            CONF;

        return $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'cronjob', 'op' => 'logrotate'],
            input: $policy,
        );
    }

    /**
     * Write the job to disk, throwing with the step that failed.
     *
     * Throws rather than returning a result because *which* step went wrong has no room in a
     * `ServerOpsResult`.
     *
     * @throws CronjobOperationException
     */
    public function write(Cronjob $cronjob): void
    {
        $path = $this->path($cronjob);

        $this->prepareLog($cronjob);

        $this->attempt('rotation', $this->ensureRotation());

        $this->attempt('write', $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'cronjob', 'op' => 'write', 'cronjob' => $cronjob->id],
            input: $this->render($cronjob),
        ));

        // 0644 or cron ignores the file — a job that silently never runs.
        $this->attempt('chmod', $this->serverOps->run(
            ['chmod', '0644', $path],
            ['feature' => 'cronjob', 'op' => 'chmod', 'cronjob' => $cronjob->id],
        ));
    }

    /**
     * Turn a failed step into an exception that names it.
     *
     * `busy` and `staleLock` are carried through: the base exception renders them differently
     * from a plain step failure.
     *
     * @throws CronjobOperationException
     */
    private function attempt(string $step, ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new CronjobOperationException(
                $result->reference,
                busy: $result->busy,
                staleLock: $result->staleLock,
                denied: $result->denied,
                step: $step,
            );
        }
    }

    /**
     * Delete the job's output log (idempotent).
     *
     * Only called when the job itself is deleted; deactivating keeps the log.
     */
    public function removeLog(Cronjob $cronjob): ServerOpsResult
    {
        return $this->serverOps->run(
            ['rm', '-f', $this->logPath($cronjob)],
            ['feature' => 'cronjob', 'op' => 'log_remove', 'cronjob' => $cronjob->id],
        );
    }

    /**
     * Follow a renamed job's log to its new slug, so past output is not orphaned.
     */
    public function moveLog(string $oldSlug, Cronjob $cronjob): ServerOpsResult
    {
        return $this->serverOps->run(
            ['mv', '-f', $this->logPathForSlug($oldSlug), $this->logPath($cronjob)],
            ['feature' => 'cronjob', 'op' => 'log_move', 'cronjob' => $cronjob->id],
        );
    }

    /**
     * Remove the job's cron.d file (idempotent — `rm -f`).
     */
    public function remove(Cronjob $cronjob): ServerOpsResult
    {
        return $this->serverOps->run(
            ['rm', '-f', $this->path($cronjob)],
            ['feature' => 'cronjob', 'op' => 'remove', 'cronjob' => $cronjob->id],
        );
    }

    /**
     * The cron.d file body: a managed header + the 6-field entry (with the
     * run-as user column) terminated by a newline, as cron requires.
     */
    private function render(Cronjob $cronjob): string
    {
        return "# Managed by the control panel — cronjob #{$cronjob->id} ({$cronjob->name}); do not edit by hand\n"
            ."{$cronjob->expression} {$cronjob->username} {$this->runLine($cronjob)}\n";
    }

    /**
     * Run the job once, now, in the background — "Run now".
     *
     * In a transient systemd unit, not the request or a queue worker: a job can take an hour and a
     * held worker stops every queued install. The unit runs as the job's account, in its home,
     * with cron's PATH and shell. The command is not %-escaped (that is crontab syntax; here it
     * would reach the shell as a literal backslash).
     *
     * The unit name is the slug, so a second Run now fails to start instead of running twice.
     */
    public function startManualRun(Cronjob $cronjob): ServerOpsResult
    {
        return $this->serverOps->run(
            [
                'systemd-run', '--quiet', '--collect',
                '--unit='.$this->manualUnit($cronjob),
                '--uid='.$cronjob->username,
                '--setenv=PATH=/usr/bin:/bin',
                '--setenv=SHELL=/bin/sh',
                '--property=WorkingDirectory=~',
                // A job stuck forever would refuse every later Run now.
                '--property=RuntimeMaxSec='.(int) config('server.cronjob_run_now_max_seconds', 86400),
                // systemd expands `$VAR` and turns `$$` into `$` itself; doubling every `$` hands
                // the shell the line unchanged. `%` is passed through, so it is left alone.
                '/bin/sh', '-c', str_replace('$', '$$', $this->shellLine($cronjob->command, $this->logPath($cronjob), manual: true)),
            ],
            ['feature' => 'cronjob', 'op' => 'run_now', 'cronjob' => $cronjob->id],
            timeout: 30,
        );
    }

    /**
     * Whether a Run now of this job is still going. Runs started by cron are not units and are
     * not counted (the history shows them as running from their log markers).
     */
    public function manualRunActive(Cronjob $cronjob): bool
    {
        $state = trim($this->serverOps->run(
            ['systemctl', 'is-active', $this->manualUnit($cronjob).'.service'],
            ['feature' => 'cronjob', 'op' => 'run_now_state', 'cronjob' => $cronjob->id],
            timeout: 15,
            expectedExitCodes: [3],
        )->output());

        return in_array($state, ['active', 'activating', 'reloading', 'deactivating'], true);
    }

    public function manualUnit(Cronjob $cronjob): string
    {
        return 'panel-cron-'.$cronjob->slug;
    }

    /**
     * Create the job's log if it is missing, owned by its account — for a
     * Run now of a job written before output capture existed.
     *
     * @throws CronjobOperationException
     */
    public function ensureLog(Cronjob $cronjob): void
    {
        $this->prepareLog($cronjob);
    }

    /**
     * The command as cron will run it: output redirected to the job's log,
     * between a start line and its exit status.
     *
     * The command is wrapped in a subshell: in `a; b >> log` the redirect binds to `b` alone and
     * `a`'s output is lost. A subshell, not braces, so a job ending in `exit` cannot skip the
     * status line.
     *
     * The status line separates "printed nothing" from "failed instantly". `$?` is expanded before
     * the date substitution runs, so it is the job's status, not `date`'s.
     *
     * The start line gives the duration, and `$$` (the shell, alive until the job ends) ties an
     * exit to its start when runs overlap and tells a running job from one killed by a reboot.
     * {@see CronjobHistory} reads all three.
     */
    private function runLine(Cronjob $cronjob): string
    {
        return $this->shellLine($this->escapePercent($cronjob->command), $this->logPath($cronjob));
    }

    private function shellLine(string $command, string $log, bool $manual = false): string
    {
        return 'echo "--- start pid=$$'.($manual ? ' manual' : '').' at $(date -Is)" >> '.$log
            .'; ( '.$command.' )'
            ." >> {$log} 2>&1; echo \"--- exit=\$? pid=\$\$ at \$(date -Is)\" >> {$log}";
    }

    /**
     * In a crontab an unescaped `%` ends the command and the rest becomes stdin; `date +%Y` would
     * be silently truncated, taking our redirect (and so the failure output) with it.
     */
    private function escapePercent(string $command): string
    {
        return str_replace('%', '\%', $command);
    }
}
