<?php

namespace App\Exceptions\Server\Firewall;

use App\Exceptions\Server\ServerOperationException;

class FirewallOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/firewall.operation_failed';
    }
}
