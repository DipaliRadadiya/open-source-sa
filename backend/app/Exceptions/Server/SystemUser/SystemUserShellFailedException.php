<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class SystemUserShellFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.shell_failed';
    }
}
