<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class SystemUserSshFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.ssh_failed';
    }
}
