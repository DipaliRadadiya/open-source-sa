<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

class ContainerLoginFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/system-user.container_login_failed';
    }
}
