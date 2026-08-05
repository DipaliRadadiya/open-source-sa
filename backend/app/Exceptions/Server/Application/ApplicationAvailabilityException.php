<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class ApplicationAvailabilityException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.availability_failed';
    }
}
