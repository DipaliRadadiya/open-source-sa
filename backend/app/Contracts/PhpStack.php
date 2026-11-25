<?php

namespace App\Contracts;

use App\Services\Server\ServerOpsResult;

/**
 * How PHP is served on this server.
 *
 * nginx and Apache talk to PHP-FPM. OpenLiteSpeed runs LSPHP over LSAPI, with its own packages,
 * paths and ini files and no per-version service. A server runs one web server, so it has one
 * PHP stack; this answers "where is PHP here" so nothing else has to assume.
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
     * The binary the web server spawns to serve PHP, or null when it talks to
     * a daemon instead.
     *
     * FPM is a daemon on a socket. LSPHP is a binary OpenLiteSpeed starts itself and is **not**
     * the CLI: the LSAPI build is `bin/lsphp`, and `bin/php` does not speak its protocol.
     */
    public function handlerPath(string $version): ?string;

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
     * Whether a site on this stack already runs as its own Linux user without
     * anything extra being configured.
     *
     * False for PHP-FPM: a site runs as the shared web-server account until it is given a pool of
     * its own (the isolation feature). True for LSPHP, where each site's `extprocessor` block
     * carries its own `extUser`/`extGroup` and socket.
     */
    public function isolatesByDefault(): bool;

    /**
     * Whether extensions can be switched on and off independently of being installed.
     *
     * Debian's `phpenmod` symlinks a module's ini into each SAPI's conf.d, so FPM has two states.
     * LiteSpeed's packages drop their ini straight into a directory LSPHP loads, with no tool to
     * unlink it: there an extension is enabled exactly when installed, so no toggle can exist.
     */
    public function togglesExtensions(): bool;

    /**
     * Switch an extension on or off across every SAPI.
     *
     * A command rather than a tool path: `phpenmod` is Debian's and only knows `/etc/php`, so
     * elsewhere it would exit zero having changed nothing.
     *
     * Takes the package's modules, not its name: the `mysql` package is `mysqli`, `mysqlnd` and
     * `pdo_mysql`, and `phpdismod mysql` exits 0 having changed nothing.
     *
     * @param  array<int, string>  $modules
     * @return array<int, string>
     */
    public function extensionToggleCommand(string $version, array $modules, bool $enable): array;

    /** Validate the configuration without changing anything. */
    public function configTest(string $version): ServerOpsResult;

    /**
     * The same check as a command, for callers that run it themselves.
     *
     * @return array<int, string>
     */
    public function configTestCommand(string $version): array;

    /**
     * What to run so that bare `php` resolves to this version.
     *
     * A list, because the stacks need different commands:
     *
     * **FPM:** ondrej's `php8.x-cli` already registered the `php`, `phar` and `phar.phar`
     * alternative groups, so each is a plain `--set` (`phar` must move too).
     *
     * **LSPHP:** LiteSpeed's packages never call `update-alternatives`, so the `php` group must be
     * created first or `--set` fails with "no alternatives for php". There is no lsphp `phar`.
     *
     * `fatal` separates "the thing the user asked for" from the rest, so a missing secondary
     * group does not report a change that did happen as one that did not.
     *
     * @return array<int, array{command: array<int, string>, fatal: bool}>
     */
    public function defaultCommands(string $version): array;

    /**
     * The package-name prefix for a version: `php8.4-` | `lsphp84-`. Callers
     * that build patterns rather than a single package name need this on its
     * own — apt-cache searches and purge globs are prefix-shaped.
     */
    public function packagePrefix(string $version): string;

    /**
     * The directory an uninstall should clear out afterwards, or null when the
     * stack has none.
     *
     * `apt purge` cannot empty it: the panel writes a pool file per site into
     * `<version>/fpm/pool.d`, dpkg does not own those, and a directory with unknown files survives
     * the purge. Detection reads these directories, so the version would stay listed, and a
     * stale pool would be picked up by the next install of that version.
     */
    public function residualDir(string $version): ?string;

    /**
     * A directory belonging to this version alone, whose folders an uninstall
     * may sweep once they are empty — never a file, never a folder with
     * anything in it. Null when the stack has none.
     *
     * For what the purge leaves behind when the panel's own files (ionCube) sat in the version's
     * tree: dpkg skips a folder that still held them.
     */
    public function emptyFoldersDir(string $version): ?string;

    /** The package that provides an extension: php8.4-mysql | lsphp84-mysql. */
    public function extensionPackage(string $version, string $extension): string;

    /** The package providing the interpreter itself, for install. */
    public function versionPackages(string $version): array;

    /** apt-cache search pattern that finds every installable version. */
    public function installablePattern(): string;

    /**
     * The versions in that search's output.
     *
     * Parsing lives here because the package name is not always the version: LiteSpeed ships
     * `lsphp84`, which has to become `8.4` before it can be compared with what is installed.
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

    /**
     * The directory this PHP actually scans for extra ini files.
     *
     * 🔴 **Not `sapiDir()."/conf.d"`:** that is the Debian layout, true only of php-fpm. LSPHP is
     * built with `--with-config-file-scan-dir=…/etc/php/{version}/mods-available/` and its package
     * has no `conf.d`. Ask the stack rather than compose the path: a `zend_extension` written into
     * an unscanned directory reports success and never loads.
     */
    public function scanDir(string $version, string $sapi): string;

    /** Where this version logs, or null if it has no log of its own. */
    public function logPath(string $version): ?string;
}
