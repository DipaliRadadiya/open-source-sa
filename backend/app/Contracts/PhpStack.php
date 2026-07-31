<?php

namespace App\Contracts;

use App\Services\Server\ServerOpsResult;

/**
 * How PHP is served on this server.
 *
 * nginx and Apache talk to PHP-FPM. OpenLiteSpeed cannot — it runs LSPHP over
 * LSAPI, with its own packages, its own paths, its own ini files and no
 * per-version service at all. Those are not variations on a theme; almost
 * every fact the panel knows about PHP is different between them.
 *
 * A server runs one web server, so it has one PHP stack. This is the thing
 * that answers "where is PHP here", so that nothing else has to assume.
 */
interface PhpStack
{
    /** fpm | lsphp */
    public function key(): string;

    /**
     * Installed versions, newest first.
     *
     * @return array<int, string>
     */
    public function versions(): array;

    public function installed(string $version): bool;

    /** The php.ini this stack actually reads for a version. */
    public function iniPath(string $version): string;

    /** The CLI binary, for installers and for reading a version's own config. */
    public function binaryPath(string $version): string;

    /**
     * The systemd unit for a version, or null where there is none — LSPHP
     * processes are spawned by the web server, so there is nothing per
     * version to start or stop.
     */
    public function serviceName(string $version): ?string;

    /**
     * The inverse: which installed version a unit name belongs to, or null
     * when the unit is not one of this stack's PHP units.
     */
    public function versionForService(string $unit): ?string;

    /** Apply a configuration change for a version. */
    public function reload(string $version): ServerOpsResult;

    /**
     * Switch an extension on or off across every SAPI.
     *
     * A command rather than a tool path: `phpenmod` is Debian's, it only knows
     * about `/etc/php`, and on a stack whose ini tree lives elsewhere it would
     * exit zero having changed nothing — a toggle that reports success and
     * does nothing is worse than one that fails.
     *
     * @return array<int, string>
     */
    public function extensionToggleCommand(string $version, string $extension, bool $enable): array;

    /** Validate the configuration without changing anything. */
    public function configTest(string $version): ServerOpsResult;

    /**
     * The same check as a command, for callers that run it themselves.
     *
     * @return array<int, string>
     */
    public function configTestCommand(string $version): array;

    /**
     * The package-name prefix for a version: `php8.4-` | `lsphp84-`. Callers
     * that build patterns rather than a single package name need this on its
     * own — apt-cache searches and purge globs are prefix-shaped.
     */
    public function packagePrefix(string $version): string;

    /** The package that provides an extension: php8.4-mysql | lsphp84-mysql. */
    public function extensionPackage(string $version, string $extension): string;

    /** The package providing the interpreter itself, for install. */
    public function versionPackages(string $version): array;

    /** apt-cache search pattern that finds every installable version. */
    public function installablePattern(): string;

    /**
     * The versions in that search's output.
     *
     * Parsing lives here rather than being a regex the caller applies, because
     * the package name is not always the version: LiteSpeed ships `lsphp84`,
     * which has to become `8.4` before anything can compare it with what is
     * installed or send it back to the API.
     *
     * @return array<int, string>
     */
    public function installableVersions(string $output): array;

    /**
     * SAPIs whose conf.d an extension is enabled in.
     *
     * @return array<int, string>
     */
    public function sapis(string $version): array;

    /** Directory holding a SAPI's conf.d symlinks. */
    public function sapiDir(string $version, string $sapi): string;

    /** Directory holding the mods-available ini files. */
    public function modsDir(string $version): string;

    /** Where this version logs, or null if it has no log of its own. */
    public function logPath(string $version): ?string;
}
