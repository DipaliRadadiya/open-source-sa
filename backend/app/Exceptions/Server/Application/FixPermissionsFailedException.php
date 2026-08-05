<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class FixPermissionsFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.permissions_fix_failed';
    }
}
