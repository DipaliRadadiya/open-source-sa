<?php

namespace App\Services\Server\Php\Stacks;

use App\Contracts\PhpStack;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * PHP-FPM — what nginx and Apache talk to.
 *
 * Every value here was previously spelled out at each call site. Collecting
 * them changes nothing about what the panel does on an FPM server; it just
 * means there is one place to answer the same questions for LSPHP.
 */
class FpmPhpStack implements PhpStack
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'fpm';
    }

    /**
     * Detected from the FPM config directories *and* the binary that runs
     * them, rather than from a package list.
     *
     * The config directory alone is not enough, which is what this used to
     * check. `apt remove php8.5-fpm` leaves `/etc/php/8.5/fpm` behind — its
     * contents are conffiles, and only `purge` deletes those — and the panel's
     * own pool files under `pool.d/` are not dpkg's to remove at all, so that
     * directory can outlive the package permanently. A removed version
     * therefore stayed on the PHP screen forever, offering settings for a
     * runtime that was gone and reloads of a service that could not start.
     *
     * The binary is the honest test: it is what actually serves a site, and
     * apt does take it away.
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
     * The FPM binary for a version — `/usr/sbin/php-fpm8.3` on Debian and
     * Ubuntu. Distinct from `binaryPath()`, which is the CLI: a server can
     * have `php8.3` without `php8.3-fpm`, and only the second one can serve a
     * site.
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

    public function binaryPath(string $version): string
    {
        $pattern = (string) config('server.php_binary_pattern', '/usr/bin/php{version}');

        // A blank pattern means "whatever `php` resolves to on PATH". The
        // Nextcloud installer carried this case on its own; it belongs here,
        // because an empty pattern is just as blank for the other seven.
        return $pattern === '' ? 'php' : str_replace('{version}', $version, $pattern);
    }

    /**
     * None: nginx and Apache hand requests to a running FPM daemon over a
     * socket rather than starting a binary themselves.
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

    public function togglesExtensions(): bool
    {
        return true;
    }

    /**
     * `-s ALL` deliberately: splitting cli from fpm lets a site work in a
     * browser and fail in a cron deploy, with nothing on screen to explain it.
     *
     * @return array<int, string>
     */
    public function extensionToggleCommand(string $version, string $extension, bool $enable): array
    {
        $binary = (string) config(
            'server.runtimes.php.'.($enable ? 'enmod_binary' : 'dismod_binary'),
            $enable ? '/usr/sbin/phpenmod' : '/usr/sbin/phpdismod',
        );

        return [$binary, '-v', $version, '-s', 'ALL', $extension];
    }

    public function configTest(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->configTestCommand($version),
            ['feature' => 'php', 'stack' => 'fpm', 'op' => 'config_test', 'version' => $version],
        );
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
