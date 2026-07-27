<?php

namespace App\Exceptions\Server\Setting;

use App\Exceptions\Server\ServerOperationException;

class SettingOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/setting.operation_failed';
    }
}
