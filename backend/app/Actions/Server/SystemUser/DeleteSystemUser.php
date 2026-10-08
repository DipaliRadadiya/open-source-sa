<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserDeleteFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\CrontabManager;
use App\Services\Server\ServerOps;
use Illuminate\Validation\ValidationException;

class DeleteSystemUser
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private CrontabManager $crontab,
        private AccountLock $accountLock,
    ) {}

    public function execute(SystemUser $systemUser, bool $endSessions = false): void
    {
        // Can't orphan running applications.
        if ($systemUser->applications()->exists()) {
            throw ValidationException::withMessages([
                'system_user' => [__('errors/system-user.has_applications')],
            ]);
        }

        // Serialize with every other account command (global /etc/passwd lock).
        $this->accountLock->run(function () use ($systemUser, $endSessions) {
            $this->emptyOwnGroup($systemUser->username);

            // FS-C31: asked for, the account's processes are ended first — an
            // open SSH session, a shell left running — because userdel will
            // not remove an account that has any, and the panel had no other
            // way to end them. Only when asked: it signs a person out.
            if ($endSessions) {
                $this->endProcesses($systemUser->username);
            }

            $result = $this->serverOps->run(
                ['userdel', '-r', $systemUser->username],
                ['feature' => 'system_user', 'op' => 'delete', 'system_user' => $systemUser->username],
            );

            // Exit 8 is userdel refusing because the account still has a
            // process — an SSH session, a worker, a cron run. Nothing is
            // broken and nothing was removed; the person can end it and retry.
            if ($result->exitCode() === 8) {
                throw ValidationException::withMessages([
                    'system_user' => [__('errors/system-user.has_processes', ['username' => $systemUser->username])],
                ]);
            }

            // Exit 6 is "user does not exist": removed outside the panel, so
            // the delete could never succeed and the row was stuck (bug #26).
            // Confirmed with getent before trusting it — the panel's side
            // (its row, its cron files) is all that is left to remove.
            if ($result->failed() && ! ($result->exitCode() === 6 && $this->goneFromServer($systemUser->username))) {
                $this->activityLogger->log('system_user.delete_failed', $systemUser, ['username' => $systemUser->username]);
                throw new SystemUserDeleteFailedException($result->reference);
            }

            // Remove each cron job's /etc/cron.d file before the DB cascade drops
            // the rows — otherwise the files would be orphaned and would point at
            // a now-deleted OS user.
            $cronjobs = $systemUser->cronjobs;

            foreach ($cronjobs as $cronjob) {
                $this->crontab->remove($cronjob);
                // The output log too — it outlived every user deletion, with
                // nothing left in the panel pointing at it.
                $this->crontab->removeLog($cronjob);
            }

            // Record how many cron jobs went with the user (audit breadcrumb).
            $this->activityLogger->log('system_user.deleted', $systemUser, [
                'username' => $systemUser->username,
                'cronjobs_removed' => $cronjobs->count(),
            ]);

            $systemUser->delete();
        });
    }

    /**
     * End the account's PHP workers, and nothing else (OLD-20).
     *
     * Asked when the account's application has just been deleted: its PHP
     * workers then serve a site that is gone. True when only such workers were
     * running (now ended) or nothing was; false when the account runs anything
     * else — a person's SSH session or shell is not the panel's to end.
     */
    public function endPhpWorkers(string $username): bool
    {
        $context = ['feature' => 'system_user', 'op' => 'end_php_workers', 'system_user' => $username];

        // Exit 1: the account has no processes — an answer.
        $listed = $this->serverOps->run(['ps', '-o', 'pid=,comm=', '-u', $username], $context, expectedExitCodes: [1]);

        $workers = [];

        foreach (array_filter(array_map('trim', explode("\n", $listed->output()))) as $line) {
            [$pid, $command] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');

            if (! ctype_digit($pid) || (int) $pid <= 1) {
                continue;
            }

            if (preg_match('/\A(lsphp|php-fpm|php)[\w.-]*\z/', $command) !== 1) {
                return false;
            }

            $workers[] = $pid;
        }

        if ($workers !== []) {
            $this->serverOps->run(['kill', '-KILL', ...$workers], $context, expectedExitCodes: [1]);
        }

        return true;
    }

    /**
     * Kill every process the account owns.
     *
     * `ps` then `kill`, both already granted, rather than `pkill`/`loginctl`
     * and a new sudo grant for one button. KILL rather than TERM: a shell
     * ignores TERM, and the account is about to stop existing.
     */
    private function endProcesses(string $username): void
    {
        $context = ['feature' => 'system_user', 'op' => 'end_sessions', 'system_user' => $username];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Exit 1: the account has no processes — an answer.
            $pids = array_values(array_filter(
                preg_split('/\s+/', trim($this->serverOps->run(['ps', '-o', 'pid=', '-u', $username], $context, expectedExitCodes: [1])->output())) ?: [],
                fn (string $pid): bool => ctype_digit($pid) && (int) $pid > 1,
            ));

            if ($pids === []) {
                return;
            }

            $this->serverOps->run(['kill', '-KILL', ...$pids], $context, expectedExitCodes: [1]);
            usleep(200_000);
        }
    }

    private function goneFromServer(string $username): bool
    {
        // `getent passwd` exits 2 for a name it does not know.
        return $this->serverOps->run(
            ['getent', 'passwd', $username],
            ['feature' => 'system_user', 'op' => 'delete_check_gone', 'system_user' => $username],
            expectedExitCodes: [2],
        )->exitCode() === 2;
    }

    /**
     * Take everyone else out of the user's own group, so `userdel` removes it.
     *
     * `userdel` removes a user's private group only when nobody else is in it,
     * and somebody always is: provisioning a site adds the web server's account
     * (`nobody` on OpenLiteSpeed) so it can write the site's logs. So the group
     * outlived every user the panel deleted, and the name could never be used
     * again, since creating a user refuses a name a group already has. Found on
     * a real server, 2026-09-24: six deleted users, six leftover groups.
     *
     * Only the group named after the user, and only its supplementary members.
     * Nothing still needs them there: a user with sites cannot be deleted, so
     * no site's logs depend on the membership.
     */
    private function emptyOwnGroup(string $username): void
    {
        $group = $this->serverOps->run(
            ['getent', 'group', $username],
            ['feature' => 'system_user', 'op' => 'delete_group_members', 'system_user' => $username],
        );

        if ($group->failed()) {
            return;
        }

        $members = array_filter(explode(',', trim((string) (explode(':', trim($group->output()))[3] ?? ''))));

        foreach ($members as $member) {
            $this->serverOps->run(
                ['gpasswd', '-d', $member, $username],
                ['feature' => 'system_user', 'op' => 'delete_group_member', 'system_user' => $username, 'member' => $member],
            );
        }
    }
}
