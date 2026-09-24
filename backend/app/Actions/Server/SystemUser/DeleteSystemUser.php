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

    public function execute(SystemUser $systemUser): void
    {
        // Can't orphan running applications.
        if ($systemUser->applications()->exists()) {
            throw ValidationException::withMessages([
                'system_user' => [__('errors/system-user.has_applications')],
            ]);
        }

        // Serialize with every other account command (global /etc/passwd lock).
        $this->accountLock->run(function () use ($systemUser) {
            $this->emptyOwnGroup($systemUser->username);

            $result = $this->serverOps->run(
                ['userdel', '-r', $systemUser->username],
                ['feature' => 'system_user', 'op' => 'delete', 'system_user' => $systemUser->username],
            );

            // Exit 8 is userdel refusing because the account still has a
            // process — an SSH session, a worker, a cron run. Nothing is
            // broken and nothing was removed; the person can end it and retry.
            if ($result->exitCode() === 8) {
                throw ValidationException::withMessages([
                    'system_user' => [__('errors/system-user.has_processes')],
                ]);
            }

            if ($result->failed()) {
                $this->activityLogger->log('system_user.delete_failed', $systemUser, ['username' => $systemUser->username]);
                throw new SystemUserDeleteFailedException($result->reference);
            }

            // Remove each cron job's /etc/cron.d file before the DB cascade drops
            // the rows — otherwise the files would be orphaned and would point at
            // a now-deleted OS user.
            $cronjobs = $systemUser->cronjobs;

            foreach ($cronjobs as $cronjob) {
                $this->crontab->remove($cronjob);
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
