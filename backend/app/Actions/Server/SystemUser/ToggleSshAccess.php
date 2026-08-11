<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserSshFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\SshUsersGroup;

class ToggleSshAccess
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
        private SshUsersGroup $sshUsersGroup,
    ) {}

    public function execute(SystemUser $systemUser, bool $sshAccess): void
    {
        // Membership of the `ssh-users` group is how the panel records who may
        // log in. Note that it only *enforces* that once sshd carries a
        // matching `AllowGroups ssh-users` — see SecuritySettings; without it
        // this toggle records intent rather than applying it.
        //
        // usermod/gpasswd take the global /etc/group lock — serialize with
        // every other account command.
        $this->accountLock->run(function () use ($systemUser, $sshAccess) {
            // Inside the lock, not before it: groupadd takes the same
            // /etc/group lock, and the lock is not reentrant.
            if ($sshAccess) {
                $this->sshUsersGroup->ensure();
            }

            $command = $sshAccess
                ? ['usermod', '-aG', SshUsersGroup::NAME, $systemUser->username]
                : ['gpasswd', '-d', $systemUser->username, SshUsersGroup::NAME];

            $result = $this->serverOps->run(
                $command,
                ['feature' => 'system_user', 'op' => $sshAccess ? 'ssh.enable' : 'ssh.disable', 'system_user' => $systemUser->username],
            );

            if ($result->failed()) {
                throw new SystemUserSshFailedException($result->reference);
            }

            $systemUser->update(['ssh_access' => $sshAccess]);

            $this->activityLogger->log(
                $sshAccess ? 'system_user.ssh_enabled' : 'system_user.ssh_disabled',
                $systemUser,
                ['username' => $systemUser->username],
            );
        });
    }
}
