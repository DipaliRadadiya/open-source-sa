<?php

namespace App\Services\Server;

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
     * Write (or overwrite) the job's cron.d file via a privileged process, then
     * enforce mode 0644 — cron silently ignores cron.d files that are writable
     * by group or others.
     */
    public function write(Cronjob $cronjob): ServerOpsResult
    {
        $path = $this->path($cronjob);

        $result = $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'cronjob', 'op' => 'write', 'cronjob' => $cronjob->id],
            input: $this->render($cronjob),
        );

        if ($result->failed()) {
            return $result;
        }

        return $this->serverOps->run(
            ['chmod', '0644', $path],
            ['feature' => 'cronjob', 'op' => 'chmod', 'cronjob' => $cronjob->id],
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
        return "# Managed by ServerAvatar OSS — cronjob #{$cronjob->id} ({$cronjob->name}); do not edit by hand\n"
            ."{$cronjob->expression} {$cronjob->username} {$cronjob->command}\n";
    }
}
