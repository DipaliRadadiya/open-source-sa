<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class CloneOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.clone_failed';
    }
}
