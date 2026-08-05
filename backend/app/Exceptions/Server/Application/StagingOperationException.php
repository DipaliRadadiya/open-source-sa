<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class StagingOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.staging_failed';
    }
}
