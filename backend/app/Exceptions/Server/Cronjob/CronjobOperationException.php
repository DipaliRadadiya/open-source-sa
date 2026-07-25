<?php

namespace App\Exceptions\Server\Cronjob;

use App\Exceptions\Server\ServerOperationException;

class CronjobOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/cronjob.sync_failed';
    }
}
