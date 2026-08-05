<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class WafOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.waf_failed';
    }
}
