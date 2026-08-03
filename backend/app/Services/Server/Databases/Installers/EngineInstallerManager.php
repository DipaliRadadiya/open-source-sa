<?php

namespace App\Services\Server\Databases\Installers;

use App\Contracts\EngineInstaller;
use InvalidArgumentException;

/**
 * Resolves the installer for an engine, and reports which engines the panel can
 * install at all.
 *
 * Not every supported engine is installable: MongoDB is operable today (the
 * panel manages databases on one that already exists) but has no installer yet,
 * because it needs its own apt repository. The catalog says so rather than
 * offering a button that cannot work.
 */
class EngineInstallerManager
{
    /**
     * @return array<int, string>
     */
    public function installableEngines(): array
    {
        return array_keys(array_filter(
            (array) config('server.databases.engines', []),
            fn (array $engine) => ($engine['installer'] ?? null) !== null,
        ));
    }

    public function canInstall(string $engine): bool
    {
        return in_array($engine, $this->installableEngines(), true);
    }

    public function installer(string $engine): EngineInstaller
    {
        $class = config("server.databases.engines.{$engine}.installer");

        if ($class === null) {
            throw new InvalidArgumentException("No installer for database engine [{$engine}].");
        }

        /** @var class-string<EngineInstaller> $class */
        return app($class);
    }
}
