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
    public function __construct(private ServerOps $serverOps) {}

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
     * Build the cron.d path from an explicit slug (used to locate the old file
     * when a job is renamed to a new slug). The basename is the bare slug —
     * matching the existing cron.d convention.
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
     * Remove the file an adopted job was imported from, once the panel has
     * written its own.
     *
     * Server Sync names an adopted job by the slug of its command, not by the
     * file it was read from, so `path()` points somewhere the original never
     * was. Editing such a job used to write a second cron.d file and leave the
     * first one running — the command then ran twice — and deleting it removed
     * only ours. Neither is recoverable from the slug, which is why the source
     * path is stored.
     *
     * Returns null when there is nothing to detach, or when the source is the
     * file we manage anyway (removing that would delete the job we just wrote).
     */
    public function detachSource(Cronjob $cronjob): ?ServerOpsResult
    {
        $source = (string) $cronjob->source_path;

        if ($source === '' || $source === $this->path($cronjob)) {
            return null;
        }

        return $this->serverOps->run(
            ['rm', '-f', $source],
            ['feature' => 'cronjob', 'op' => 'detach_source', 'cronjob' => $cronjob->id, 'path' => $source],
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
     * Absolute path of the job's output log. Derived from the same slug as the
     * cron.d file rather than stored, so the two can never drift apart.
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
     * Cron's `>>` would create it too, but as whichever user ran first and
     * inside a directory they may not be able to write to. Creating it here
     * means the very first run has somewhere to write, and one user's job can
     * never overwrite another's log.
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

        // The user, not `user:user`. A group of the same name exists for root
        // and for every account the panel creates, but this feature accepts any
        // account `getent` resolves — and `nobody`'s group on Debian is
        // `nogroup`, so `chown nobody:nobody` fails and takes the whole
        // creation with it. Omitting the group leaves the primary group alone,
        // which is what was wanted in every case anyway.
        $this->attempt('log_chown', $this->serverOps->run(
            ['chown', $cronjob->username, $log],
            ['feature' => 'cronjob', 'op' => 'log_chown', 'cronjob' => $cronjob->id],
        ));

        // A job's output can contain anything it printed — keep it off other
        // accounts.
        $this->attempt('log_chmod', $this->serverOps->run(
            ['chmod', '0640', $log],
            ['feature' => 'cronjob', 'op' => 'log_chmod', 'cronjob' => $cronjob->id],
        ));
    }

    /**
     * Install the logrotate policy for cron job logs (idempotent — rewritten
     * with each job write).
     *
     * This ships with the capture, not after it. A job running every minute
     * appends forever; capturing output with no bound is a disk-fill with a
     * delayed fuse, and the server it fills is the one running the sites.
     */
    private function ensureRotation(): ServerOpsResult
    {
        $dir = rtrim((string) config('server.cronjob_log_dir'), '/');
        $days = (int) config('server.cronjob_log_keep_days', 14);
        $size = (string) config('server.cronjob_log_max_size', '10M');
        $path = (string) config('server.cronjob_logrotate_file');

        // copytruncate: the running job holds the file open, so rotating by
        // rename would leave it writing to a file nobody can see.
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
     * Throws rather than returning a result the caller inspects, because the
     * thing worth knowing — *which* of seven privileged steps went wrong — has
     * no room in a `ServerOpsResult`. It used to be discarded: every failure
     * here produced one sentence, so the person who had to fix it learned only
     * that something had gone wrong somewhere.
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
     * `busy` and `staleLock` are carried through: "the server is occupied" and
     * "a lock file needs removing" are different advice from "this step
     * failed", and the base exception already renders each differently.
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
                step: $step,
            );
        }
    }

    /**
     * Delete the job's output log (idempotent).
     *
     * Only called when the job itself is deleted. Deactivating a job removes
     * its cron.d file but keeps the log — a paused job's history is exactly
     * what you look at to decide whether to re-enable it.
     */
    public function removeLog(Cronjob $cronjob): ServerOpsResult
    {
        return $this->serverOps->run(
            ['rm', '-f', $this->logPath($cronjob)],
            ['feature' => 'cronjob', 'op' => 'log_remove', 'cronjob' => $cronjob->id],
        );
    }

    /**
     * Follow a renamed job's log to its new slug, so past output survives the
     * rename instead of being orphaned under a name nothing points at.
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
     * The command as cron will run it: output redirected to the job's log,
     * followed by its exit status.
     *
     * The command is wrapped in a subshell because cron entries are shell
     * lines and people write compound ones. In `a; b >> log`, the redirect
     * binds to `b` alone — `a`'s output escapes to cron's mail (i.e. nowhere)
     * and the log silently under-reports. A subshell rather than braces
     * because a job ending in `exit` would otherwise take the shell with it,
     * and the status line would never be written.
     *
     * That status line is the point of the feature: "printed nothing" and
     * "failed instantly" look identical in a bare output log, and the second
     * is what you need to know. `$?` is expanded before the date substitution
     * runs, so it is the job's status and not `date`'s.
     */
    private function runLine(Cronjob $cronjob): string
    {
        $log = $this->logPath($cronjob);

        return '( '.$this->escapePercent($cronjob->command).' )'
            ." >> {$log} 2>&1; echo \"--- exit=\$? at \$(date -Is)\" >> {$log}";
    }

    /**
     * In a crontab, an unescaped `%` ends the command and everything after it
     * becomes the job's stdin. A command like `date +%Y` would otherwise be
     * silently truncated — taking our redirect with it, so the failure would
     * also be invisible.
     */
    private function escapePercent(string $command): string
    {
        return str_replace('%', '\%', $command);
    }
}
