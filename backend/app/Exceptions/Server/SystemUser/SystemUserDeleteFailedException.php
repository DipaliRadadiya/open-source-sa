<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class SystemUserDeleteFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.delete_failed';
    }
}
