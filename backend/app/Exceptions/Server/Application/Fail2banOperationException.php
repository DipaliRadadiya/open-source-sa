<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class Fail2banOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.fail2ban_failed';
    }
}
