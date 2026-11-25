<?php

namespace App\Services\Server\Databases\Installers;

use App\Contracts\EngineInstaller;
use App\Services\Applications\SiteTypeManager;
use App\Support\OsRelease;
use InvalidArgumentException;

/**
 * Resolves the installer for an engine, and reports which engines the panel can
 * install **on this server**.
 *
 * Two separate questions:
 *
 *  - *Does the panel know how to install this engine?* Whether the config
 *    names an installer class.
 *  - *Can it be installed here?* Whether this Ubuntu release is one the
 *    engine's vendor publishes for. MongoDB builds per codename and has none
 *    for `resolute`, so the install would wait on apt and fail on a package that
 *    was never there.
 *
 * `canInstall()` answers the second, because that is the one the button depends on.
 */
class EngineInstallerManager
{
    /**
     * Engines the panel has an installer for, whether or not this server can run them.
     *
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
        return in_array($engine, $this->installableEngines(), true)
            && $this->unavailableReason($engine) === null;
    }

    /**
     * Why this engine cannot be installed here, or null when it can.
     *
     * Same shape as a blocked site type ({@see SiteTypeManager}): a stable `code` for the
     * client and an already-translated sentence.
     *
     * @return array{code: string, reason: string}|null
     */
    public function unavailableReason(string $engine): ?array
    {
        $unsupported = (array) config("server.databases.engines.{$engine}.unsupported_codenames", []);

        if ($unsupported === []) {
            return null;
        }

        $codename = OsRelease::codename();

        // An unreadable /etc/os-release means "cannot tell", which must not become "refused".
        if ($codename === null || ! in_array($codename, $unsupported, true)) {
            return null;
        }

        return [
            'code' => 'os_unsupported',
            'reason' => __('errors/database.engine_os_unsupported', [
                'engine' => (string) config("server.databases.engines.{$engine}.label", $engine),
                'os' => OsRelease::label() ?? __('runtime.this_server'),
            ]),
        ];
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

    /**
     * MySQL and MariaDB cannot share a server: apt removes one to install the other. Said up
     * front, in the words the install job uses. An engine that is installed itself is never
     * blocked: installing it again is how its panel account is repaired.
     *
     * @param  array<string, bool>  $installed
     * @return array{code: string, reason: string}|null
     */
    public function conflictReason(string $engine, array $installed): ?array
    {
        if (($installed[$engine] ?? false) || ! $this->canInstall($engine)) {
            return null;
        }

        $installer = $this->installer($engine);

        if (! $installer instanceof AbstractSqlEngineInstaller) {
            return null;
        }

        $other = $installer->conflictingEngine();

        return ($installed[$other] ?? false)
            ? ['code' => 'engine_conflict', 'reason' => __('runtime.install_failed.port_in_use_by_'.$other)]
            : null;
    }
}
