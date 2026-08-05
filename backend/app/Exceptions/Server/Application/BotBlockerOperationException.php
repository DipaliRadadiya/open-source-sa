<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

class BotBlockerOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.bot_blocker_failed';
    }
}
