<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class SystemUserPasswordFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.password_failed';
    }
}
