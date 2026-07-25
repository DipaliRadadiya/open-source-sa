<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class SystemUserSudoFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.sudo_failed';
    }
}
