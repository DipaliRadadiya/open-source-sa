<?php

namespace App\Exceptions\Server\WebServer;

use App\Exceptions\Server\ServerOperationException;

/**
 * The configuration was written and passed the web server's own test, and the
 * reload that should have made it live failed.
 *
 * Its own failure rather than the feature's, because what to say is the same
 * everywhere and is not "your change failed": the change is saved and on disk,
 * and the web server is still serving the settings it had before. Reported
 * instead of the success those callers used to answer with, which told the
 * user something was live that was not.
 */
class WebServerReloadFailedException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/web-server.reload_failed';
    }

    protected function code(): string
    {
        return 'web_server_reload_failed';
    }
}
