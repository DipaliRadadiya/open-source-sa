<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

/**
 * A site delete that waited out its wait window while the site's certificate
 * was being issued. Always constructed `busy`, so the base render() answers
 * 503 "try again" with a reference; the delete had not changed anything yet.
 */
class CertificateBusyException extends ServerOperationException
{
    protected function messageKey(): string
    {
        // Unused in practice: this exception is always busy, and the base
        // render() maps busy to errors/server.busy regardless of this key.
        return 'errors/server.busy';
    }
}
