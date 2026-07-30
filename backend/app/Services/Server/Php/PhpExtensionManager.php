<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Services\Server\ServerOps;

/**
 * The PHP extensions available to a version, which are installed, and which
 * are switched on.
 *
 * Two axes, not one. An extension is *installed* when its apt package put a
 * `.so` on disk and an ini in `mods-available`; it is *enabled* when that ini
 * is symlinked into a SAPI's `conf.d`. Debian's postinst enables on install,
 * so on a fresh box the two look identical — they only diverge once somebody
 * deliberately turns something off, which is exactly the case this screen
 * exists for.
 *
 * The catalog is keyed by **package**, not module, because those are not the
 * same thing: `php8.4-mysql` is one package providing `mysqli`, `mysqlnd` and
 * `pdo_mysql`. A design keyed on a single name is wrong for a good chunk of
 * the list, and the user thinks in packages anyway — they want "mysql", not
 * three checkboxes that must move together.
 *
 * Package → modules is read from the shipped `.so` files rather than from
 * `mods-available`: the core packages *generate* their ini in postinst, so
 * dpkg does not own it and cannot tell you who it belongs to. The `.so` is
 * always shipped.
 */
class PhpExtensionManager
{
    public function __construct(
        private ServerOps $serverOps,
        private PhpVersionManager $versions,
        private PhpStack $stack,
    ) {}

    /**
     * Every extension this version could have, with its current state.
     *
     * @return array<int, array{name: string, package: string, modules: array<int, string>, installed: bool, enabled: bool, builtin: bool, sapis: array<string, bool>}>
     */
    public function catalog(string $version): array
    {
        $this->assertVersion($version);

        $installedModules = $this->installedModules($version);
        $packageModules = $this->packageModules($version, $installedModules);
        $enabled = $this->enabledBySapi($version);
        $enabledEverywhere = $this->intersect(array_values($enabled));

        $rows = [];

        foreach ($this->availablePackages($version) as $name) {
            $modules = $packageModules[$name] ?? [$name];
            $installed = isset($packageModules[$name]);

            $rows[] = [
                'name' => $name,
                'package' => $this->stack->extensionPackage($version, $name),
                'modules' => $modules,
                'installed' => $installed,
                // On only when every module the package provides is on. A
                // half-enabled package is off as far as the toggle is
                // concerned, because that is what it behaves like.
                'enabled' => $installed && $modules === array_values(array_intersect($modules, $enabledEverywhere)),
                'builtin' => false,
                'sapis' => $this->sapiState($modules, $enabled),
            ];
        }

        // Compiled into the binary: present, useful to see, impossible to turn
        // off. Listed without a control rather than given a button that lies.
        foreach ($this->builtins($version, $installedModules) as $name) {
            $rows[] = [
                'name' => $name,
                'package' => null,
                'modules' => [$name],
                'installed' => true,
                'enabled' => true,
                'builtin' => true,
                'sapis' => [],
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['installed'], $a['name']] <=> [$a['installed'], $b['name']]);

        return $rows;
    }

    /**
     * @return array{name: string, package: string, modules: array<int, string>, installed: bool, enabled: bool, builtin: bool, sapis: array<string, bool>}|null
     */
    public function find(string $version, string $name): ?array
    {
        foreach ($this->catalog($version) as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Turn an extension on in every SAPI, then apply the change.
     *
     * Which tool does that is the stack's business: `phpenmod` is Debian's and
     * only understands `/etc/php`.
     */
    public function enable(string $version, string $name): void
    {
        $this->toggle($version, $name, enable: true);
    }

    public function disable(string $version, string $name): void
    {
        $this->toggle($version, $name, enable: false);
    }

    /**
     * Install the package behind an extension. Slow — apt — so this is called
     * from a job, never from a request.
     */
    public function install(string $version, string $name): void
    {
        $this->assertVersion($version);

        $result = $this->serverOps->run(
            ['apt-get', 'install', '-y', '--no-install-recommends', $this->stack->extensionPackage($version, $name)],
            ['feature' => 'php', 'op' => 'extension_install', 'version' => $version, 'extension' => $name],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        if ($result->failed()) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }

        // The package enables itself, but nothing tells FPM.
        $this->reload($version);
    }

    /**
     * Modules the panel cannot run without — refused on the panel's own
     * version only. Disabling pdo_sqlite under the panel means the request to
     * turn it back on never gets answered.
     *
     * @return array<int, string>
     */
    public function panelRequired(): array
    {
        return (array) config('server.runtimes.php.panel_required', []);
    }

    /**
     * Which of a package's modules the panel depends on, if any.
     *
     * @param  array<int, string>  $modules
     * @return array<int, string>
     */
    public function panelBlockers(array $modules): array
    {
        return array_values(array_intersect($modules, $this->panelRequired()));
    }

    private function toggle(string $version, string $name, bool $enable): void
    {
        $this->assertVersion($version);

        $result = $this->serverOps->run(
            $this->stack->extensionToggleCommand($version, $name, $enable),
            ['feature' => 'php', 'op' => $enable ? 'extension_enable' : 'extension_disable', 'version' => $version, 'extension' => $name],
        );

        if ($result->failed()) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }

        $this->reload($version);
    }

    /**
     * phpenmod moves symlinks and stops there — it has no idea a daemon is
     * reading them. Without this the toggle flips in the UI and changes
     * nothing at all until something else happens to restart FPM.
     */
    private function reload(string $version): void
    {
        // LSPHP has no per-version unit at all, so there is nothing here to
        // reload — the stack decides what applying a change means.
        if ($this->stack->serviceName($version) === null) {
            return;
        }

        $this->stack->reload($version);
    }

    /**
     * Extension packages apt knows about for this version.
     *
     * Read from the package index rather than hardcoded — a box with the
     * Ondřej archive has ~84, a plain distro far fewer, and both are correct
     * for that box. Non-extension packages that share the prefix (the SAPIs,
     * `-common`, `-dev`) are dropped: they are not things to toggle.
     *
     * @return array<int, string>
     */
    private function availablePackages(string $version): array
    {
        $output = $this->serverOps->run(
            ['apt-cache', 'search', '--names-only', '^'.$this->stack->packagePrefix($version)],
            ['feature' => 'php', 'op' => 'extension_catalog', 'version' => $version],
        )->output();

        $excluded = (array) config('server.runtimes.php.non_extension_packages', []);

        $prefix = preg_quote($this->stack->packagePrefix($version), '/');
        preg_match_all('/^'.$prefix.'([a-z0-9_]+)\s/m', $output, $matches);

        return collect($matches[1] ?? [])
            ->unique()
            ->reject(fn (string $name) => in_array($name, $excluded, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Modules with an ini in mods-available — i.e. installed, whether or not
     * they are switched on anywhere.
     *
     * @return array<int, string>
     */
    private function installedModules(string $version): array
    {
        $dir = $this->stack->modsDir($version);

        return collect(glob($dir.'/*.ini') ?: [])
            ->map(fn (string $path) => basename($path, '.ini'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Which package provides which modules, for the installed ones.
     *
     * Asked of dpkg in a single call over the extension directory rather than
     * once per package: the answer is the same and 80 subprocesses to render
     * one screen is not.
     *
     * @param  array<int, string>  $installedModules
     * @return array<string, array<int, string>>
     */
    private function packageModules(string $version, array $installedModules): array
    {
        $dir = $this->extensionDir($version);
        $objects = $dir !== null ? (glob($dir.'/*.so') ?: []) : [];

        if ($objects === []) {
            // No extension dir to ask about (a version installed without its
            // CLI, or a test fixture). Fall back to assuming the package name
            // is the module name, which is true for all but a handful.
            return collect($installedModules)->mapWithKeys(fn (string $m) => [$m => [$m]])->all();
        }

        $output = $this->serverOps->run(
            ['dpkg-query', '-S', ...$objects],
            ['feature' => 'php', 'op' => 'extension_owners', 'version' => $version],
        )->output();

        $map = [];

        // php8.4-mysql: /usr/lib/php/20240924/pdo_mysql.so
        $prefix = preg_quote($this->stack->packagePrefix($version), '/');
        preg_match_all('/^'.$prefix.'([a-z0-9_]+):\s*(\S+\.so)$/m', $output, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $package, $path]) {
            $module = basename($path, '.so');

            if (in_array($module, $installedModules, true)) {
                $map[$package][] = $module;
            }
        }

        foreach ($map as $package => $modules) {
            $map[$package] = array_values(array_unique($modules));
            sort($map[$package]);
        }

        return $map;
    }

    /**
     * Enabled modules, per SAPI, read from the conf.d symlinks — which are
     * the truth phpquery itself reports.
     *
     * @return array<string, array<int, string>>
     */
    private function enabledBySapi(string $version): array
    {
        $enabled = [];

        foreach ($this->stack->sapis($version) as $sapi) {
            $dir = $this->sapiDir($version, $sapi);

            if (! is_dir($dir)) {
                continue;
            }

            // 20-curl.ini -> curl
            $enabled[$sapi] = collect(glob($dir.'/conf.d/*.ini') ?: [])
                ->map(fn (string $path) => preg_replace('/^\d+-/', '', basename($path, '.ini')))
                ->values()
                ->all();
        }

        return $enabled;
    }

    /**
     * @param  array<int, string>  $modules
     * @param  array<string, array<int, string>>  $enabled
     * @return array<string, bool>
     */
    private function sapiState(array $modules, array $enabled): array
    {
        return collect($enabled)
            ->map(fn (array $on) => $modules === array_values(array_intersect($modules, $on)))
            ->all();
    }

    /**
     * Extensions compiled into the binary — in `php -m`, absent from
     * mods-available, and not removable by any means the panel has.
     *
     * @param  array<int, string>  $installedModules
     * @return array<int, string>
     */
    private function builtins(string $version, array $installedModules): array
    {
        $output = $this->serverOps->run(
            [$this->binary($version), '-m'],
            ['feature' => 'php', 'op' => 'loaded_modules', 'version' => $version],
        )->output();

        $lowerInstalled = array_map([$this, 'normaliseModule'], $installedModules);

        return collect(preg_split('/\r?\n/', trim($output)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '['))
            ->map(fn (string $line) => $this->normaliseModule($line))
            ->reject(fn (string $module) => in_array($module, $lowerInstalled, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Where this version keeps its `.so` files. Asked of the binary rather
     * than guessed: the directory is named after PHP's internal API date
     * (`/usr/lib/php/20240924`), which differs per version and is not
     * derivable from the version number.
     */
    private function extensionDir(string $version): ?string
    {
        $result = $this->serverOps->run(
            [$this->binary($version), '-r', 'echo ini_get("extension_dir");'],
            ['feature' => 'php', 'op' => 'extension_dir', 'version' => $version],
        );

        $dir = trim($result->output());

        return $result->ok && $dir !== '' && is_dir($dir) ? $dir : null;
    }

    /**
     * `php -m` uses display names, not module names: OPcache comes back as
     * "Zend OPcache" while its package and ini are plain `opcache`. Compared
     * naively, it is missing from the installed set and gets listed a second
     * time as a built-in — one extension, two rows, one of them a lie.
     */
    private function normaliseModule(string $name): string
    {
        return str_replace(' ', '', strtolower(preg_replace('/^zend\s+/i', '', trim($name)) ?? $name));
    }

    private function binary(string $version): string
    {
        return $this->stack->binaryPath($version);
    }

    private function sapiDir(string $version, string $sapi): string
    {
        return $this->stack->sapiDir($version, $sapi);
    }

    private function assertVersion(string $version): void
    {
        if (! $this->versions->exists($version)) {
            throw PhpConfigException::unknownVersion($version);
        }
    }

    /**
     * @param  array<int, array<int, string>>  $sets
     * @return array<int, string>
     */
    private function intersect(array $sets): array
    {
        if ($sets === []) {
            return [];
        }

        return array_values(array_intersect(...$sets));
    }
}
