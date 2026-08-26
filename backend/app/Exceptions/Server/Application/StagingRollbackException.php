<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class StagingRollbackException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.staging_rollback_failed';
    }

    protected function code(): string
    {
        return 'staging_rollback_failed';
    }
}
