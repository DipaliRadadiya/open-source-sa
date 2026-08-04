<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserShellFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;

class ChangeShell
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
    ) {}

    public function execute(SystemUser $systemUser, string $shell): void
    {
        // usermod takes the global /etc/passwd lock — serialize with every
        // other account command.
        $this->accountLock->run(function () use ($systemUser, $shell) {
            $result = $this->serverOps->run(
                ['usermod', '-s', $shell, $systemUser->username],
                ['feature' => 'system_user', 'op' => 'shell', 'system_user' => $systemUser->username, 'shell' => $shell],
            );

            if ($result->failed()) {
                throw new SystemUserShellFailedException($result->reference);
            }

            $systemUser->update(['shell' => $shell]);

            $this->activityLogger->log('system_user.shell_changed', $systemUser, [
                'username' => $systemUser->username,
                'shell' => $shell,
            ]);
        });
    }
}
