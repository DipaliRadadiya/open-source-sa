<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserSudoFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;

class ToggleSudo
{
    public function __construct(
        private ServerOps $serverOps,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(SystemUser $systemUser, bool $sudo): void
    {
        $command = $sudo
            ? ['usermod', '-aG', 'sudo', $systemUser->username]
            : ['gpasswd', '-d', $systemUser->username, 'sudo'];

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'system_user', 'op' => $sudo ? 'sudo.enable' : 'sudo.disable', 'system_user' => $systemUser->username],
        );

        if ($result->failed()) {
            throw new SystemUserSudoFailedException($result->reference);
        }

        $systemUser->update(['sudo' => $sudo]);

        $this->activityLogger->log(
            $sudo ? 'system_user.sudo_enabled' : 'system_user.sudo_disabled',
            $systemUser,
            ['username' => $systemUser->username],
        );
    }
}
