<?php

namespace App\Services\Server\Runtimes;

use App\Contracts\Runtime;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * PHP versions, managed with apt.
 *
 * Where Node needed a version manager bolted on, PHP already has one: the
 * distribution — with Ondřej Surý's archive on Ubuntu — packages every version
 * side by side, at predictable paths, with a systemd unit each. So there is no
 * fnm equivalent here and no invented layout; the panel installs a package and
 * reads what apt put on disk.
 *
 * The default is `update-alternatives`, which Debian provides for exactly this
 * and which other packages already respect. Managing `/usr/bin/php` by hand
 * instead would fight the package manager on its own ground.
 *
 * Detection deliberately reuses PhpVersionManager — the same source the
 * Services screen and the ini editor read — so the three can never disagree
 * about which versions exist.
 */
class PhpRuntime implements Runtime
{
    public function __construct(
        private ServerOps $serverOps,
        private PhpVersionManager $versions,
    ) {}

    public function key(): string
    {
        return 'php';
    }

    public function manager(): string
    {
        return 'apt';
    }

    /**
     * @return array<int, array{version: string, path: string, is_default: bool, source: string}>
     */
    public function versions(): array
    {
        $default = $this->default();

        return array_map(fn (string $version) => [
            'version' => $version,
            'path' => $this->binaryPath($version),
            'is_default' => $version === $default,
            'source' => 'apt',
            // The version the panel itself is running on. Removing it would
            // take the panel down, which is not a thing the panel should be
            // able to do to itself.
            'in_use_by_panel' => $version === $this->panelVersion(),
        ], $this->versions->versions());
    }

    /**
     * What bare `php` resolves to, according to update-alternatives.
     */
    public function default(): ?string
    {
        $output = $this->serverOps->run(
            ['update-alternatives', '--query', 'php'],
            ['feature' => 'runtime', 'op' => 'php_default'],
        )->output();

        // Value: /usr/bin/php8.4
        return preg_match('/^Value:\s*\S*php(\d+\.\d+)\s*$/m', $output, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Always null: every PHP here came from a package, so there is no
     * unmanaged install to report the way there is for Node.
     *
     * @return array{version: string, path: string}|null
     */
    public function system(): ?array
    {
        return null;
    }

    /**
     * Versions apt could install that are not installed already.
     *
     * Read from the package index rather than hardcoded, so a server with the
     * Ondřej archive sees the full range and one without sees only what its
     * distribution ships — which is the truth in both cases.
     *
     * @return array<int, string>
     */
    public function installable(): array
    {
        $output = $this->serverOps->run(
            ['apt-cache', 'search', '--names-only', '^php[0-9]+\.[0-9]+-fpm$'],
            ['feature' => 'runtime', 'op' => 'php_installable'],
        )->output();

        preg_match_all('/^php(\d+\.\d+)-fpm\s/m', $output, $matches);

        $installed = $this->versions->versions();

        return collect($matches[1] ?? [])
            ->unique()
            ->reject(fn (string $version) => in_array($version, $installed, true))
            ->sortByDesc(fn (string $version) => (float) $version)
            ->values()
            ->all();
    }

    public function installed(string $version): bool
    {
        return in_array($version, $this->versions->versions(), true);
    }

    public function binaryPath(string $version): string
    {
        return str_replace('{version}', $version, (string) config('server.php_binary_pattern', '/usr/bin/php{version}'));
    }

    /**
     * The version the panel itself runs on.
     */
    public function panelVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /**
     * Install a version, with the extensions a site is unusable without.
     *
     * A bare `phpX.Y-fpm` has no mysql, no curl, no mbstring — every
     * application in the marketplace would fail on it. The base set is
     * configurable rather than assumed, but it is not empty.
     *
     * @throws SettingOperationException
     */
    public function install(string $version): void
    {
        $packages = array_map(
            fn (string $package) => "php{$version}-{$package}",
            (array) config('server.runtimes.php.base_packages', ['fpm', 'cli', 'common']),
        );

        $this->must($this->serverOps->run(
            ['apt-get', 'install', '-y', '--no-install-recommends', ...$packages],
            ['feature' => 'runtime', 'op' => 'php_install', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            // apt refuses to run unattended without this, and a prompt with
            // nobody to answer it hangs until the timeout.
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
        ));
    }

    /**
     * Point bare `php` at a version.
     *
     * Only the CLI default moves. Sites keep whatever version their FPM pool
     * runs — changing this must not migrate a running site.
     *
     * @throws SettingOperationException
     */
    public function setDefault(string $version): void
    {
        $this->must($this->serverOps->run(
            ['update-alternatives', '--set', 'php', $this->binaryPath($version)],
            ['feature' => 'runtime', 'op' => 'php_default_set', 'version' => $version],
        ));
    }

    /**
     * @throws SettingOperationException
     */
    public function uninstall(string $version): void
    {
        $this->must($this->serverOps->run(
            ['apt-get', 'purge', '-y', "php{$version}-*"],
            ['feature' => 'runtime', 'op' => 'php_uninstall', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
        ));
    }

    private function must(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }
}
