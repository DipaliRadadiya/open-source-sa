<?php

namespace App\Exceptions\Server\Database;

use App\Exceptions\Server\ServerOperationException;

class DatabaseOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/database.operation_failed';
    }
}
