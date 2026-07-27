<?php

namespace App\Exceptions\Server\Service;

use App\Exceptions\Server\ServerOperationException;

class ServiceOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/service.operation_failed';
    }
}
