<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class BasicAuthOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.basic_auth_failed';
    }
}
