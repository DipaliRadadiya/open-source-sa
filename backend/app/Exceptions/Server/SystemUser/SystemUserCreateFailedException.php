<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class SystemUserCreateFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.create_failed';
    }
}
