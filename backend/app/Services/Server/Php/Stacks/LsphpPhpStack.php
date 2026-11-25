<?php

namespace App\Services\Server\Php\Stacks;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Services\Server\Php\IniSyntaxCheck;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * LSPHP — how OpenLiteSpeed runs PHP.
 *
 * OLS cannot use PHP-FPM; it runs LSPHP over LSAPI. Packages are `lsphp84-…` (compact, no dot),
 * the tree is under `/usr/local/lsws` rather than `/etc/php`, the ini is the `litespeed` SAPI's,
 * and there is no per-version service: lshttpd spawns the processes, so applying a change means
 * restarting the web server. Paths and package names are read from `config/server.php`.
 */
class LsphpPhpStack implements PhpStack
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'lsphp';
    }

    /**
     * Detected from the lsws tree, newest first (directories, not the package list).
     *
     * @return array<int, string>
     */
    public function versions(): array
    {
        $dir = $this->root();

        if (! is_dir($dir)) {
            return [];
        }

        $versions = [];

        foreach (glob($dir.'/lsphp*', GLOB_ONLYDIR) ?: [] as $path) {
            $version = $this->expand(substr(basename($path), strlen('lsphp')));

            // The directory is not the evidence: `lsphp83/` is left behind by a removed version, and
            // packaging creates the tree before the interpreter lands. Require the interpreter.
            if ($version !== null && $this->hasInterpreter($version)) {
                $versions[] = $version;
            }
        }

        usort($versions, fn (string $a, string $b) => version_compare($b, $a));

        return $versions;
    }

    public function installed(string $version): bool
    {
        return in_array($version, $this->versions(), true);
    }

    public function iniPath(string $version): string
    {
        return $this->interpolate(
            (string) config('server.php_stacks.lsphp.ini_path', '{root}/lsphp{compact}/etc/php/{version}/litespeed/php.ini'),
            $version,
        );
    }

    /**
     * The CLI, found rather than assumed.
     *
     * LSPHP is not always in the lsws tree: some builds use `/usr/bin/lsphp8.2` (with a dot,
     * where the lsws tree uses `lsphp82`), so one hardcoded pattern is wrong on those installs.
     */
    public function binaryPath(string $version): string
    {
        return $this->detect('binary_candidates', [
            '{root}/lsphp{compact}/bin/php',
            '/usr/bin/lsphp{version}',
            '/usr/local/bin/lsphp{version}',
            '{root}/lsphp{compact}/bin/lsphp',
        ], $version);
    }

    /**
     * `bin/lsphp`, not `bin/php`.
     *
     * `lsphp` is the LSAPI build the web server speaks to; `php` is the ordinary CLI. A vhost
     * pointed at the CLI would run no PHP.
     */
    public function handlerPath(string $version): ?string
    {
        // LSAPI build only: `bin/php` is deliberately not in this list.
        return $this->detect('handler_candidates', [
            '{root}/lsphp{compact}/bin/lsphp',
            '/usr/bin/lsphp{version}',
            '/usr/local/bin/lsphp{version}',
        ], $version);
    }

    /**
     * None: LSPHP processes belong to lshttpd, so there is no `php8.4-fpm` to start or stop.
     * Callers use this to decide whether PHP adds rows to the Services screen.
     */
    public function serviceName(string $version): ?string
    {
        return null;
    }

    public function versionForService(string $unit): ?string
    {
        return null;
    }

    /**
     * Restart the web server, because that is what respawns LSPHP (no per-version process to
     * signal). `lswsctrl restart` is graceful: old workers drain.
     */
    public function reload(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            (array) config('server.php_stacks.lsphp.reload_command', ['/usr/local/lsws/bin/lswsctrl', 'restart']),
            ['feature' => 'php', 'stack' => 'lsphp', 'op' => 'reload', 'version' => $version],
        );
    }

    /**
     * The nearest equivalent of `php-fpm -t`.
     *
     * No LSPHP command validates a php.ini, and `openlitespeed -t` checks the web server's config
     * only. So the version's own binary is started with that ini: it catches an ini PHP cannot
     * load, but is a weaker guarantee than FPM's.
     */
    public function configTest(string $version): ServerOpsResult
    {
        // Exit 0 is not a pass: PHP starts over an ini it could not parse.
        return IniSyntaxCheck::apply($this->serverOps->run(
            $this->configTestCommand($version),
            ['feature' => 'php', 'stack' => 'lsphp', 'op' => 'config_test', 'version' => $version],
        ));
    }

    /**
     * @return array<int, string>
     */
    public function configTestCommand(string $version): array
    {
        return [$this->binaryPath($version), '-c', $this->iniPath($version), '-v'];
    }

    /**
     * Register the group, then select it.
     *
     * `--set` alone fails with `no alternatives for php` when no ondrej PHP is installed:
     * LiteSpeed's packages never call update-alternatives. `--install` creates the group (or adds
     * lsphp to an existing one). The priority is the compact version (85 for 8.5), so a later PHP
     * outranks an earlier one in automatic mode; `--set` switches to manual.
     *
     * `/usr/local/bin/php` is what decides the answer: `install.sh` creates it for PHARs' shebang
     * and `/usr/local/bin` precedes `/usr/bin`, so without moving it `php -v` keeps the old
     * version. The panel's own interpreter does not depend on it (PanelPhpBinary::runningSibling()).
     *
     * `phar` is registered too (`<tree>/bin/phar8.4`); non-fatal because the filename is derived,
     * not detected.
     *
     * @return array<int, array{command: array<int, string>, fatal: bool}>
     */
    public function defaultCommands(string $version): array
    {
        $binary = $this->binaryPath($version);
        $priority = $this->compact($version);
        $phar = dirname($binary).'/phar'.$version;

        return [
            [
                'command' => ['update-alternatives', '--install', '/usr/bin/php', 'php', $binary, $priority],
                'fatal' => true,
            ],
            [
                'command' => ['update-alternatives', '--set', 'php', $binary],
                'fatal' => true,
            ],
            // The one that makes `php -v` agree with the screen.
            [
                'command' => ['ln', '-sfn', $binary, '/usr/local/bin/php'],
                'fatal' => true,
            ],
            [
                'command' => ['update-alternatives', '--install', '/usr/bin/phar', 'phar', $phar, $priority],
                'fatal' => false,
            ],
            [
                'command' => ['update-alternatives', '--set', 'phar', $phar],
                'fatal' => false,
            ],
        ];
    }

    /**
     * `lsphp84-`, not `lsphp8.4-`: LiteSpeed's packages drop the dot.
     */
    public function packagePrefix(string $version): string
    {
        return 'lsphp'.$this->compact($version).'-';
    }

    /**
     * None: the panel writes no per-version directory here, and pointing this at `lsphpNN/` would
     * make a purge delete parts of the web server.
     */
    public function residualDir(string $version): ?string
    {
        return null;
    }

    /**
     * `lsphpNN/`, LiteSpeed's tree for one version. Only its empty folders
     * are ever swept — see residualDir() for why nothing more.
     */
    public function emptyFoldersDir(string $version): ?string
    {
        return $this->root().'/lsphp'.$this->compact($version);
    }

    public function extensionPackage(string $version, string $extension): string
    {
        return $this->packagePrefix($version).$extension;
    }

    /**
     * @return array<int, string>
     */
    public function versionPackages(string $version): array
    {
        $packages = (array) config('server.php_stacks.lsphp.base_packages', ['common', 'mysql', 'curl']);

        // The interpreter package has no suffix: `lsphp84`, not `lsphp84-lsphp`.
        return [
            'lsphp'.$this->compact($version),
            ...array_map(fn (string $package) => $this->extensionPackage($version, $package), $packages),
        ];
    }

    public function installablePattern(): string
    {
        return '^lsphp[0-9]+$';
    }

    /**
     * `lsphp84` → `8.4`.
     *
     * Left compact, `84` never matches the `8.4` that `versions()` reports and fails the
     * endpoint's `major.minor` rule.
     *
     * @return array<int, string>
     */
    public function installableVersions(string $output): array
    {
        preg_match_all('/^lsphp(\d+)\s/m', $output, $matches);

        return array_values(array_filter(array_map(
            fn (string $compact) => $this->expand($compact),
            $matches[1] ?? [],
        )));
    }

    /**
     * One SAPI, where FPM has two.
     *
     * @return array<int, string>
     */
    public function sapis(string $version): array
    {
        return (array) config('server.php_stacks.lsphp.sapis', ['litespeed']);
    }

    public function sapiDir(string $version, string $sapi): string
    {
        return dirname($this->iniPath($version), 2).'/'.$sapi;
    }

    public function modsDir(string $version): string
    {
        return dirname($this->iniPath($version), 2).'/mods-available';
    }

    /**
     * `mods-available`, and **not** a per-SAPI `conf.d`.
     *
     * `lsphp85` is built with:
     *
     *   --with-config-file-path=/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/
     *   --with-config-file-scan-dir=/usr/local/lsws/lsphp85/etc/php/8.5/mods-available/
     *
     * The package has no `conf.d`: the scanned directory holds the ini files themselves.
     * `$sapi` is ignored because there is only one (`litespeed`).
     *
     * Never compose `sapiDir()."/conf.d"` here: it is absent and not scanned, so an extension
     * install would report success and never load.
     */
    public function scanDir(string $version, string $sapi): string
    {
        return $this->modsDir($version);
    }

    /**
     * LSPHP writes into the web server's error log, so there is no per-version file; null keeps
     * it off the Logs screen.
     */
    public function logPath(string $version): ?string
    {
        return null;
    }

    /**
     * Every site's vhost names its own `extUser` and socket, so a site is isolated the moment it
     * is written; there is no pool for the isolation feature to create.
     */
    public function isolatesByDefault(): bool
    {
        return true;
    }

    /**
     * No: LiteSpeed's packages put their ini where LSPHP reads it and ship nothing to unlink it,
     * so installed and enabled are the same state.
     */
    public function togglesExtensions(): bool
    {
        return false;
    }

    /**
     * Refused, not silently ignored: `phpenmod` only understands `/etc/php` and would exit zero
     * having changed nothing in the lsws tree.
     *
     * @return array<int, string>
     *
     * @throws PhpConfigException
     */
    public function extensionToggleCommand(string $version, array $modules, bool $enable): array
    {
        throw PhpConfigException::unsupportedOnStack($this->key());
    }

    /**
     * The first candidate that exists, or the first as a last resort (an absolute path names the
     * file we expected; a bare PATH name fails as "command not found").
     *
     * @param  array<int, string>  $defaults
     */
    /**
     * Is there actually an interpreter for this version on disk?
     *
     * The LSAPI handler, which is what the vhost points at; a tree holding only the CLI is not
     * servable. Separate from `handlerPath()`, which always answers with a path, so "did it find
     * one?" cannot be read off a value that is never empty.
     */
    private function hasInterpreter(string $version): bool
    {
        $candidates = array_map(
            fn (string $pattern) => $this->interpolate($pattern, $version),
            (array) config('server.php_stacks.lsphp.handler_candidates', [
                '{root}/lsphp{compact}/bin/lsphp',
                '/usr/bin/lsphp{version}',
                '/usr/local/bin/lsphp{version}',
            ]),
        );

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    private function detect(string $key, array $defaults, string $version): string
    {
        $candidates = array_map(
            fn (string $pattern) => $this->interpolate($pattern, $version),
            (array) config("server.php_stacks.lsphp.{$key}", $defaults),
        );

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0] ?? '';
    }

    /**
     * 8.4 → 84, the form LiteSpeed names everything after.
     */
    private function compact(string $version): string
    {
        return str_replace('.', '', $version);
    }

    /**
     * 84 → 8.4. Anything that is not two-or-more digits is something else in the lsws tree and is
     * skipped.
     */
    private function expand(string $compact): ?string
    {
        if (preg_match('/^(\d)(\d+)$/', $compact, $matches) !== 1) {
            return null;
        }

        return $matches[1].'.'.$matches[2];
    }

    private function interpolate(string $pattern, string $version): string
    {
        return str_replace(
            ['{root}', '{compact}', '{version}'],
            [$this->root(), $this->compact($version), $version],
            $pattern,
        );
    }

    private function root(): string
    {
        return rtrim((string) config('server.php_stacks.lsphp.dir', '/usr/local/lsws'), '/');
    }
}
