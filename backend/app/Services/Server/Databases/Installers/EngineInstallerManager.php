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
 * Two separate questions, and conflating them is what made the setup page offer
 * MongoDB on Ubuntu 26.04:
 *
 *  - *Does the panel know how to install this engine?* — whether the config
 *    names an installer class. Every shipped engine has one now.
 *  - *Can it be installed here?* — whether this Ubuntu release is one the
 *    engine's vendor publishes for. MongoDB builds per codename and has none
 *    for `resolute`, so the install writes an apt source and a signing key,
 *    waits two minutes for apt, and fails on a package that was never there.
 *
 * `canInstall()` answers the second, because that is the one the button depends
 * on. The first is still true and still useless to a user.
 */
class EngineInstallerManager
{
    /**
     * Engines the panel has an installer for, before asking what this server
     * can actually run.
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
     * Same shape the site-type catalog uses for a blocked card
     * ({@see SiteTypeManager}) — a stable `code` for
     * the client to branch on and a sentence already translated for the viewer
     * — so the frontend renders a blocked engine and a blocked site type the
     * same way instead of learning a second convention.
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

        // An unreadable /etc/os-release means the panel does not know which
        // release this is — and "I cannot tell" must not become "refused". The
        // install still classifies the real failure if it turns out to be one;
        // guessing here would block an engine on a server we failed to read.
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
}
