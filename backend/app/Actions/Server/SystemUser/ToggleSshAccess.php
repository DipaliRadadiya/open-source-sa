<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserSshFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;

class ToggleSshAccess
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
    ) {}

    public function execute(SystemUser $systemUser, bool $sshAccess): void
    {
        // Membership of the `ssh-users` group gates SSH login, provided
        // sshd_config carries `AllowGroups ssh-users` (configured by server
        // provisioning). Same group-toggle pattern as sudo.
        //
        // usermod/gpasswd take the global /etc/group lock — serialize with
        // every other account command.
        $this->accountLock->run(function () use ($systemUser, $sshAccess) {
            $command = $sshAccess
                ? ['usermod', '-aG', 'ssh-users', $systemUser->username]
                : ['gpasswd', '-d', $systemUser->username, 'ssh-users'];

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
