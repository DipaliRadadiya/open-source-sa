<?php

namespace App\Services\Server\Php\Stacks;

use App\Contracts\PhpStack;
use App\Services\Server\Php\IniSyntaxCheck;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * PHP-FPM — what nginx and Apache talk to.
 */
class FpmPhpStack implements PhpStack
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'fpm';
    }

    /**
     * Detected from the FPM config directories *and* the binary that runs them.
     *
     * The config directory alone is not enough: `apt remove php8.5-fpm` leaves `/etc/php/8.5/fpm`
     * behind (conffiles survive until `purge`, and the panel's `pool.d/` files are not dpkg's).
     * The binary is what serves a site, and apt does remove it.
     *
     * @return array<int, string>
     */
    public function versions(): array
    {
        $dir = $this->root();

        if (! is_dir($dir)) {
            return [];
        }

        $versions = array_filter(
            array_map(
                fn (string $fpm) => basename(dirname($fpm)),
                glob($dir.'/*/fpm', GLOB_ONLYDIR) ?: [],
            ),
            fn (string $version): bool => is_file($this->fpmBinaryPath($version)),
        );

        usort($versions, fn (string $a, string $b) => version_compare($b, $a));

        return array_values($versions);
    }

    /**
     * The FPM binary for a version (`/usr/sbin/php-fpm8.3` on Debian/Ubuntu). Distinct from
     * `binaryPath()`, the CLI: a server can have `php8.3` without `php8.3-fpm`.
     */
    public function fpmBinaryPath(string $version): string
    {
        return str_replace(
            '{version}',
            $version,
            (string) config('server.php_fpm_binary_pattern', '/usr/sbin/php-fpm{version}'),
        );
    }

    public function installed(string $version): bool
    {
        return in_array($version, $this->versions(), true);
    }

    public function iniPath(string $version): string
    {
        return $this->root()."/{$version}/fpm/php.ini";
    }

    /**
     * `/etc/php/8.5` — everything for one version, including panel-written config.
     *
     * Safe to remove only after a successful purge (its only caller).
     */
    public function residualDir(string $version): ?string
    {
        return $this->root()."/{$version}";
    }

    /**
     * None: the loader sits in `/usr/lib/php/{abi}`, shared between versions with the same ABI.
     */
    public function emptyFoldersDir(string $version): ?string
    {
        return null;
    }

    public function binaryPath(string $version): string
    {
        $pattern = (string) config('server.php_binary_pattern', '/usr/bin/php{version}');

        // A blank pattern means whatever `php` resolves to on PATH.
        return $pattern === '' ? 'php' : str_replace('{version}', $version, $pattern);
    }

    /**
     * None: nginx and Apache hand requests to a running FPM daemon over a socket.
     */
    public function handlerPath(string $version): ?string
    {
        return null;
    }

    public function serviceName(string $version): ?string
    {
        return "php{$version}-fpm";
    }

    public function versionForService(string $unit): ?string
    {
        foreach ($this->versions() as $version) {
            if ($this->serviceName($version) === $unit) {
                return $version;
            }
        }

        return null;
    }

    public function reload(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', 'reload', (string) $this->serviceName($version)],
            ['feature' => 'php', 'stack' => 'fpm', 'op' => 'reload', 'version' => $version],
        );
    }

    /**
     * A site runs as the web server's account until it gets its own pool. {@see PoolIsolator}
     */
    public function isolatesByDefault(): bool
    {
        return false;
    }

    public function togglesExtensions(): bool
    {
        return true;
    }

    /**
     * `-s ALL` deliberately: splitting cli from fpm lets a site work in a browser and fail in cron.
     *
     * @return array<int, string>
     */
    public function extensionToggleCommand(string $version, array $modules, bool $enable): array
    {
        $binary = (string) config(
            'server.runtimes.php.'.($enable ? 'enmod_binary' : 'dismod_binary'),
            $enable ? '/usr/sbin/phpenmod' : '/usr/sbin/phpdismod',
        );

        // All in one call: one module at a time leaves a window where mysqli is on and the
        // mysqlnd it needs is not.
        return [$binary, '-v', $version, '-s', 'ALL', ...$modules];
    }

    public function configTest(string $version): ServerOpsResult
    {
        // Exit 0 is not a pass: PHP starts over an ini it could not parse.
        return IniSyntaxCheck::apply($this->serverOps->run(
            $this->configTestCommand($version),
            ['feature' => 'php', 'stack' => 'fpm', 'op' => 'config_test', 'version' => $version],
        ));
    }

    /**
     * Each installed version validates itself: `php-fpm8.4 -t`.
     *
     * @return array<int, string>
     */
    public function configTestCommand(string $version): array
    {
        return [(string) config('server.php_fpm_binary', '/usr/sbin/php-fpm').$version, '-t'];
    }

    /**
     * Three alternatives groups, not one: `php8.x-cli` registers `php`, `phar` and `phar.phar`,
     * and moving only `php` leaves `phar` on the old default version.
     *
     * The two `phar` groups are not fatal: a server may have the interpreter without them.
     *
     * @return array<int, array{command: array<int, string>, fatal: bool}>
     */
    public function defaultCommands(string $version): array
    {
        return [
            ['command' => ['update-alternatives', '--set', 'php', $this->binaryPath($version)], 'fatal' => true],
            ['command' => ['update-alternatives', '--set', 'phar', '/usr/bin/phar'.$version], 'fatal' => false],
            ['command' => ['update-alternatives', '--set', 'phar.phar', '/usr/bin/phar.phar'.$version], 'fatal' => false],
        ];
    }

    public function packagePrefix(string $version): string
    {
        return "php{$version}-";
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
        return array_map(
            fn (string $package) => $this->extensionPackage($version, $package),
            (array) config('server.runtimes.php.base_packages', ['fpm', 'cli', 'common']),
        );
    }

    public function installablePattern(): string
    {
        return '^php[0-9]+\\.[0-9]+-fpm$';
    }

    /**
     * @return array<int, string>
     */
    public function installableVersions(string $output): array
    {
        preg_match_all('/^php(\\d+\\.\\d+)-fpm\\s/m', $output, $matches);

        return $matches[1] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function sapis(string $version): array
    {
        return (array) config('server.runtimes.php.sapis', ['cli', 'fpm']);
    }

    public function sapiDir(string $version, string $sapi): string
    {
        return $this->root()."/{$version}/{$sapi}";
    }

    public function modsDir(string $version): string
    {
        return $this->root()."/{$version}/mods-available";
    }

    /**
     * Debian's layout: `mods-available` holds the ini files, each SAPI's `conf.d` holds the
     * numbered symlinks the interpreter reads, so cli and fpm can differ.
     */
    public function scanDir(string $version, string $sapi): string
    {
        return $this->sapiDir($version, $sapi).'/conf.d';
    }

    public function logPath(string $version): ?string
    {
        return str_replace(
            '{version}',
            $version,
            (string) config('server.php_fpm_log', '/var/log/php{version}-fpm.log'),
        );
    }

    private function root(): string
    {
        return rtrim((string) config('server.php_dir', '/etc/php'), '/');
    }
}
