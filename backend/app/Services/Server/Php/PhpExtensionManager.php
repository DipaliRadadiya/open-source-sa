<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Enums\InstallStatus;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Models\RuntimeInstall;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\ServerOps;

/**
 * The PHP extensions available to a version, which are installed, and which
 * are switched on.
 *
 * Two axes: *installed* (the apt package put a `.so` on disk and an ini in
 * `mods-available`) and *enabled* (that ini is symlinked into a SAPI's `conf.d`).
 *
 * The catalog is keyed by package, not module: `php8.4-mysql` provides `mysqli`,
 * `mysqlnd` and `pdo_mysql`. Package → modules is read from the shipped `.so` files,
 * because core packages generate their ini in postinst, so dpkg does not own it.
 */
class PhpExtensionManager
{
    public function __construct(
        private ServerOps $serverOps,
        private PhpVersionManager $versions,
        private PhpStack $stack,
        private InstallTracker $installs,
        private InstallFailureClassifier $classifier,
    ) {}

    /**
     * Every extension this version could have, with its current state.
     *
     * @return array<int, array{name: string, package: string, modules: array<int, string>, installed: bool, enabled: bool, builtin: bool, sapis: array<string, bool>, status: string, started_at: ?string, reason: ?string, message: ?string, reference: ?string}>
     */
    public function catalog(string $version): array
    {
        $this->assertVersion($version);

        $installedModules = $this->installedModules($version);
        $packageModules = $this->packageModules($version, $installedModules);
        $enabled = $this->enabledBySapi($version);
        $enabledEverywhere = $this->intersect(array_values($enabled));

        // In-flight and last-failed apt runs for this version's extensions (one query).
        $installs = $this->installs->extensions('php', $version);

        $rows = [];

        foreach ($this->availablePackages($version) as $name => $summary) {
            $modules = $packageModules[$name] ?? [$name];
            $installed = isset($packageModules[$name]);

            $rows[] = [
                'name' => $name,
                'package' => $this->stack->extensionPackage($version, $name),
                'modules' => $modules,
                'installed' => $installed,
                // On only when every module the package provides is on.
                'enabled' => $installed && $modules === array_values(array_intersect($modules, $enabledEverywhere)),
                'builtin' => false,
                // apt's one-line description (English, from the package index).
                'summary' => $summary,
                'sapis' => $this->sapiState($modules, $enabled),
                // The state of the *operation*, not of the extension: `ready` means nothing
                // is in flight.
                ...$this->progress($installs->get($name)),
            ];
        }

        // Compiled into the binary: listed without a control, since it cannot be turned off.
        foreach ($this->builtins($version, $installedModules) as $name) {
            $rows[] = [
                'name' => $name,
                'package' => null,
                'modules' => [$name],
                'installed' => true,
                'enabled' => true,
                'builtin' => true,
                'summary' => null,
                'sapis' => [],
                // Compiled in: no apt package, so never mid-install.
                ...$this->progress(null),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['installed'], $a['name']] <=> [$a['installed'], $b['name']]);

        return $rows;
    }

    /**
     * @return array{name: string, package: string, modules: array<int, string>, installed: bool, enabled: bool, builtin: bool, sapis: array<string, bool>, status: string, started_at: ?string, reason: ?string, message: ?string, reference: ?string}|null
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
     * Which tool does that is the stack's business (`phpenmod` only understands `/etc/php`).
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
     * Whether an installed extension can be switched off on this stack.
     */
    public function togglesExtensions(): bool
    {
        return $this->stack->togglesExtensions();
    }

    /**
     * Install the package behind an extension. Slow (apt): call from a job, never a request.
     */
    public function install(string $version, string $name, ?callable $onOutput = null): void
    {
        $this->assertVersion($version);

        $result = $this->serverOps->apt(
            ['apt-get', 'install', '-y', '--no-install-recommends', $this->stack->extensionPackage($version, $name)],
            ['feature' => 'php', 'op' => 'extension_install', 'version' => $version, 'extension' => $name],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            // Same apt call as a version install, so the same phases and output.
            onOutput: $onOutput,
        );

        // Same classification as a version install (the screen lists both together).
        if ($result->failed()) {
            throw new RuntimeInstallException(
                $result->reference,
                $this->classifier->classify('php', $result),
            );
        }

        // With no toggle the ini already sits where the interpreter reads it; only a restart
        // is needed. enable() would refuse and report a failed install for a successful apt run.
        if (! $this->stack->togglesExtensions()) {
            try {
                $this->reload($version);
            } catch (PhpConfigException $e) {
                throw new RuntimeInstallException((string) $e->reference, 'reload_failed');
            }

            return;
        }

        // Debian's postinst normally enables the module, but that is not guaranteed. Enable
        // explicitly (a no-op if already on; it also does the FPM reload).
        try {
            $this->enable($version, $name);
        } catch (PhpConfigException $e) {
            // Installed but not switched on is its own outcome, not "the install failed";
            // switched on but not reloaded is not "could not be switched on" either.
            throw new RuntimeInstallException(
                (string) $e->reference,
                $e->reason() === 'reload_failed' ? 'reload_failed' : 'enable_failed',
            );
        }
    }

    /**
     * Modules the panel cannot run without, refused on the panel's own version only
     * (disabling pdo_sqlite under the panel means the request to re-enable it never gets
     * answered).
     *
     * @return array<int, string>
     */
    public function panelRequired(): array
    {
        $required = (array) config('server.runtimes.php.panel_required', []);

        // The fixed list does not know the panel's config: cache, queue and sessions may run
        // on phpredis, and redis.so will not load without igbinary.
        if (config('database.redis.client') === 'phpredis') {
            array_push($required, 'redis', 'igbinary');
        }

        $required = [...$required, ...match (config('database.connections.'.config('database.default').'.driver')) {
            'mysql', 'mariadb' => ['pdo_mysql', 'mysqlnd'],
            'pgsql' => ['pdo_pgsql'],
            default => [],
        }];

        return array_values(array_unique($required));
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

    /**
     * The progress fields for a catalog row.
     *
     * @return array<string, mixed>
     */
    private function progress(?RuntimeInstall $install): array
    {
        return $install?->toProgress() ?? [
            'status' => InstallStatus::Ready->value,
            'queued' => false,
            'started_at' => null,
            'started_at_human' => null,
            'reason' => null,
            'message' => null,
            'reference' => null,
            'error_line' => null,
            // Same keys whether or not an install touched this extension.
            'current_step' => null,
            'output' => null,
        ];
    }

    private function toggle(string $version, string $name, bool $enable): void
    {
        $this->assertVersion($version);

        $modules = $this->find($version, $name)['modules'] ?? [$name];

        $result = $this->serverOps->run(
            $this->stack->extensionToggleCommand($version, $modules, $enable),
            ['feature' => 'php', 'op' => $enable ? 'extension_enable' : 'extension_disable', 'version' => $version, 'extension' => $name],
        );

        if ($result->failed()) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }

        $this->reload($version);

        // Read back, not assumed: `phpdismod` exits 0 for a name it has no ini for.
        if (($this->find($version, $name)['enabled'] ?? null) !== $enable) {
            throw PhpConfigException::extensionUnchanged($version, $name, $enable, $result->reference);
        }
    }

    /**
     * phpenmod only moves symlinks; the daemon reading them must be reloaded or the toggle
     * changes nothing until something else restarts FPM.
     */
    private function reload(string $version): void
    {
        // Asked of every stack, not skipped when there is no systemd unit: LSPHP has no unit
        // but reloads by restarting the web server that spawns it (otherwise workers keep
        // running without the change). The result is read: ignored, a failed reload would
        // report success.
        $result = $this->stack->reload($version);

        if ($result->failed()) {
            throw PhpConfigException::reloadFailed($version, $result->reference);
        }
    }

    /**
     * Extension packages apt knows about for this version.
     *
     * Read from the package index, not hardcoded (the Ondřej archive has far more than a
     * plain distro). Non-extension packages sharing the prefix (SAPIs, `-common`, `-dev`)
     * are dropped. Keyed by extension, with apt's one-line description (null when none).
     *
     * @return array<string, string|null>
     */
    private function availablePackages(string $version): array
    {
        $output = $this->serverOps->run(
            ['apt-cache', 'search', '--names-only', '^'.$this->stack->packagePrefix($version)],
            ['feature' => 'php', 'op' => 'extension_catalog', 'version' => $version],
        )->output();

        $excluded = (array) config('server.runtimes.php.non_extension_packages', []);

        $prefix = preg_quote($this->stack->packagePrefix($version), '/');
        preg_match_all('/^'.$prefix.'([a-z0-9_]+)(?:[ \t]+-[ \t]+([^\n]*))?[ \t]*$/m', $output, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match) => [$match[1] => trim($match[2] ?? '') ?: null])
            ->reject(fn (?string $summary, string $name) => in_array($name, $excluded, true))
            // `lsphp84-ioncube` beside the ionCube card's loader would be two loaders in one PHP.
            ->reject(fn (?string $summary, string $name) => $this->isIonCube($name))
            ->sortKeys()
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

        // LiteSpeed's PECL packages number their ini (`50-redis.ini`), Debian's are bare;
        // moduleName() strips the number.
        return collect(glob($dir.'/*.ini') ?: [])
            ->map(fn (string $path) => $this->moduleName($path))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Which package provides which modules, for the installed ones.
     *
     * Asked of dpkg in a single call over the extension directory, not once per package.
     *
     * @param  array<int, string>  $installedModules
     * @return array<string, array<int, string>>
     */
    private function packageModules(string $version, array $installedModules): array
    {
        $dir = $this->extensionDir($version);
        $objects = $dir !== null ? (glob($dir.'/*.so') ?: []) : [];

        if ($objects === []) {
            // No extension dir to ask about: assume package name = module name.
            return collect($installedModules)->mapWithKeys(fn (string $m) => [$m => [$m]])->all();
        }

        $output = $this->serverOps->run(
            ['dpkg-query', '-S', ...$objects],
            ['feature' => 'php', 'op' => 'extension_owners', 'version' => $version],
            // Exit 1 when some file belongs to no package (ionCube); the rest is still on stdout.
            expectedExitCodes: [1],
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
     * Enabled modules, per SAPI, read from the conf.d symlinks (what phpquery reports).
     *
     * @return array<string, array<int, string>>
     */
    private function enabledBySapi(string $version): array
    {
        // No toggle means installed *is* enabled: there are no conf.d symlinks to read
        // (globbing a missing conf.d would report everything disabled).
        if (! $this->stack->togglesExtensions()) {
            return [$this->stack->sapis($version)[0] ?? 'litespeed' => $this->installedModules($version)];
        }

        $enabled = [];

        foreach ($this->stack->sapis($version) as $sapi) {
            $dir = $this->sapiDir($version, $sapi);

            if (! is_dir($dir)) {
                continue;
            }

            // 20-curl.ini -> curl
            $enabled[$sapi] = collect(glob($dir.'/conf.d/*.ini') ?: [])
                ->map(fn (string $path) => $this->moduleName($path))
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

        // `[PHP Modules]` only: `[Zend Modules]` repeats them under display names.
        $section = preg_split('/^\[Zend Modules\]\s*$/m', $output)[0] ?? $output;

        return collect(preg_split('/\r?\n/', trim($section)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '['))
            ->map(fn (string $line) => $this->normaliseModule($line))
            ->reject(fn (string $module) => in_array($module, $lowerInstalled, true))
            // "ionCube Loader" belongs to the ionCube card, not a built-in.
            ->reject(fn (string $module) => $this->isIonCube($module))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Where this version keeps its `.so` files. Asked of the binary: the directory is named
     * after PHP's API date (`/usr/lib/php/20240924`), not derivable from the version.
     */
    private function extensionDir(string $version): ?string
    {
        // A startup warning ("Module 'redis' is already loaded") is printed on stdout before
        // the answer: startup output off, and the answer read as the last line that is a path.
        $result = $this->serverOps->run(
            [$this->binary($version), '-d', 'display_startup_errors=0', '-d', 'display_errors=0', '-r', 'echo "\n", ini_get("extension_dir");'],
            ['feature' => 'php', 'op' => 'extension_dir', 'version' => $version],
        );

        $paths = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->output()) ?: []),
            fn (string $line): bool => str_starts_with($line, '/'),
        ));
        $dir = (string) end($paths);

        return $result->ok && $dir !== '' && is_dir($dir) ? $dir : null;
    }

    /** ionCube has its own card ({@see IonCubeLoader}); never a row here. */
    private function isIonCube(string $name): bool
    {
        return str_contains(strtolower($name), 'ioncube');
    }

    /** `20-curl.ini` -> `curl`, `curl.ini` -> `curl`. */
    private function moduleName(string $iniPath): string
    {
        return (string) preg_replace('/^\d+-/', '', basename($iniPath, '.ini'));
    }

    /**
     * `php -m` uses display names ("Zend OPcache" vs the ini's `opcache`); unnormalised it
     * would be listed a second time as a built-in.
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
