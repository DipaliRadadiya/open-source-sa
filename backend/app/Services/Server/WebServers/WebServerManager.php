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
