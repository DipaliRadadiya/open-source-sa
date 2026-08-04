<?php

namespace App\Exceptions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;

/**
 * The global account-mutation lock could not be acquired within its wait
 * window — another account command (useradd/usermod/passwd/…) is still
 * running. Always constructed `busy`, so the base render() answers 503
 * "try again"; nothing was changed.
 */
class AccountBusyException extends ServerOperationException
{
    protected function messageKey(): string
    {
        // Unused in practice: this exception is always busy, and the base
        // render() maps busy to errors/server.busy regardless of this key.
        return 'errors/server.busy';
    }
}
