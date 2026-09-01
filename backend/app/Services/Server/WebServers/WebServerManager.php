<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Exceptions\Server\Application\UnsupportedWebServerException;
use App\Services\Server\Capabilities\ServerCapabilities;

/**
 * Resolves the driver for whatever web server this server actually runs.
 * Nothing else in the feature names a web server.
 */
class WebServerManager
{
    public function __construct(private ServerCapabilities $capabilities) {}

    /**
     * @throws UnsupportedWebServerException when the server runs something we
     *                                       cannot configure — better to refuse
     *                                       than to write a config we guessed.
     */
    public function driver(): WebServerDriver
    {
        // Repair a record the box contradicts before acting on it. This is the
        // one place every feature passes through on its way to writing a
        // vhost, and it is where a wrong value stops being a stored mistake
        // and becomes a file written to a directory that does not exist.
        //
        // The alternative was leaving it to `panel:doctor`, which means the
        // panel knows it is wrong, keeps failing, and waits to be asked. On the
        // server that hit this, site creation, WAF and every other vhost write
        // failed with a different-looking error each time, all of them the same
        // stored value. Cheap by construction: it is a directory check unless
        // the recorded server is genuinely missing, and memoised per request.
        $this->capabilities->reconcileWebServer();

        $name = $this->capabilities->webServer();
        $class = config("server.web_server_drivers.{$name}.driver");

        if ($name === null || $class === null) {
            throw new UnsupportedWebServerException($name);
        }

        return app($class);
    }

    public function supports(?string $name): bool
    {
        return $name !== null && config("server.web_server_drivers.{$name}.driver") !== null;
    }
}
