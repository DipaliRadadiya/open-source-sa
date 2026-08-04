<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserPasswordFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\ServerOps;

class SetSystemUserPassword
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
        private AccountLock $accountLock,
    ) {}

    public function execute(SystemUser $systemUser, string $password): void
    {
        // chpasswd takes the global /etc/passwd lock, so serialize it with
        // every other account command or it collides with a concurrent
        // create/usermod for a different user.
        $this->accountLock->run(function () use ($systemUser, $password) {
            // Password is piped to chpasswd's stdin — never in the command array
            // or the log context.
            $result = $this->serverOps->run(
                ['chpasswd'],
                ['feature' => 'system_user', 'op' => 'password', 'system_user' => $systemUser->username],
                input: $systemUser->username.':'.$password,
            );

            if ($result->failed()) {
                $this->activityLogger->log('system_user.password_failed', $systemUser, ['username' => $systemUser->username]);
                throw new SystemUserPasswordFailedException($result->reference);
            }

            // Operator decision (2026-07-25): the plaintext password is stored on the
            // row so an admin can copy it for server login. It is written here only
            // after the OS change succeeds; it is still never placed in the command
            // array or the server-ops log (piped to chpasswd's stdin above).
            $systemUser->update(['password' => $password]);

            $this->activityLogger->log('system_user.password_set', $systemUser, ['username' => $systemUser->username]);
        });
    }
}
