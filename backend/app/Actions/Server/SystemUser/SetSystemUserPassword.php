<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserPasswordFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;

class SetSystemUserPassword
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(SystemUser $systemUser, string $password): void
    {
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

        $this->activityLogger->log('system_user.password_set', $systemUser, ['username' => $systemUser->username]);
    }
}
