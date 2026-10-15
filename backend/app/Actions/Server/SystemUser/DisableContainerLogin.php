<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\ContainerLoginFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\AppShell\AppShellFiles;
use App\Services\Server\ServerOps;

/**
 * Turn container login off (RC-28): the user leaves `sv-app-shell`, and with
 * it the forced command. Nothing else changes — if SSH access is still on,
 * their next login is an ordinary one on the server, exactly as the System
 * Users screen says.
 */
class DisableContainerLogin
{
    public function __construct(
        private ServerOps $serverOps,
        private AccountLock $accountLock,
        private AppShellFiles $files,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws ContainerLoginFailedException
     */
    public function execute(SystemUser $systemUser): void
    {
        $this->accountLock->run(function () use ($systemUser): void {
            // Exit 3 is gpasswd's "not a member": already off is off.
            $removed = $this->serverOps->run(
                ['gpasswd', '-d', $systemUser->username, $this->files->group()],
                ['feature' => 'app_shell', 'op' => 'login.disable', 'system_user' => $systemUser->username],
                expectedExitCodes: [3],
            );

            if ($removed->failed() && $removed->exitCode() !== 3) {
                throw new ContainerLoginFailedException($removed->reference);
            }

            $systemUser->update(['container_login' => false]);
        });

        $this->activityLogger->log('system_user.container_login_disabled', $systemUser, [
            'username' => $systemUser->username,
        ]);
    }
}
