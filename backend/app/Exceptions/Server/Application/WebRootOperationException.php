<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class WebRootOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.web_root_failed';
    }
}
