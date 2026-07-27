<?php

namespace App\Exceptions\Server\DiskCleaner;

use App\Exceptions\Server\ServerOperationException;

class DiskCleanerException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/disk-cleaner.operation_failed';
    }
}
