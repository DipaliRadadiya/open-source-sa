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
     * Absolute path of the managed cron.d file for a job. The basename is
     * run-parts-safe ([A-Za-z0-9_-], no dots) or cron would ignore it.
     */
    public function path(Cronjob $cronjob): string
    {
        return rtrim(config('server.cron_d'), '/').'/sv-oss-cronjob-'.$cronjob->id;
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
     * Write (or overwrite) the job's cron.d file via a privileged process.
     */
    public function write(Cronjob $cronjob): ServerOpsResult
    {
        return $this->serverOps->run(
            ['tee', $this->path($cronjob)],
            ['feature' => 'cronjob', 'op' => 'write', 'cronjob' => $cronjob->id],
            input: $this->render($cronjob),
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
