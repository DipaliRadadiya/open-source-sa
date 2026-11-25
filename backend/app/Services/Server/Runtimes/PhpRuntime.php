<?php

namespace App\Services\Server\Runtimes;

use App\Contracts\PhpStack;
use App\Contracts\Runtime;
use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\FpmReloadGrace;
use App\Services\Server\Php\IonCubeLoader;
use App\Services\Server\Php\LegacyPhpTimezone;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\ServerTimezone;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PHP versions, managed with apt.
 *
 * The distribution (with Ondřej Surý's archive on Ubuntu) packages every version side by side, at
 * predictable paths, with a systemd unit each, so there is no fnm equivalent: the panel installs a
 * package and reads what apt put on disk.
 *
 * The default is `update-alternatives`; managing `/usr/bin/php` by hand would fight the package
 * manager. Detection reuses PhpVersionManager (also read by the Services screen and the ini
 * editor), so they cannot disagree about which versions exist.
 */
class PhpRuntime implements Runtime
{
    public function __construct(
        private ServerOps $serverOps,
        private PhpVersionManager $versions,
        private PhpStack $stack,
        private InstallFailureClassifier $classifier,
        private IonCubeLoader $ionCube,
        private ManagedFile $files,
        private FpmReloadGrace $reloadGrace,
        private LegacyPhpTimezone $legacyTimezone,
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
            // The version the panel itself runs on; removing it would take the panel down.
            'in_use_by_panel' => $version === $this->panelVersion(),
        ], $this->versions->versions());
    }

    /**
     * What bare `php` resolves to: the PATH link, else update-alternatives.
     *
     * The path is matched against the stack's own `binaryPath()` per installed version rather than
     * parsed by pattern: `php(\d+\.\d+)` fits `/usr/bin/php8.4` but not LSPHP paths (`lsphp85`, no
     * dot, ending in a bare `php`), so an OLS box reported no default.
     */
    public function default(): ?string
    {
        // `/usr/local/bin/php` first: where it exists it precedes /usr/bin on PATH, so it, not the
        // alternative, is what `php` runs. install.sh creates it on OpenLiteSpeed and registers no
        // alternative. Trusted only when it names a version listed here; else fall through.
        $link = (string) config('server.php_path_link', '/usr/local/bin/php');

        if ($link !== '' && is_link($link) && ($target = readlink($link)) !== false
            && ($version = $this->versionAt($target)) !== null) {
            return $version;
        }

        $output = $this->serverOps->run(
            ['update-alternatives', '--query', 'php'],
            ['feature' => 'runtime', 'op' => 'php_default'],
        )->output();

        if (preg_match('/^Value:\s*(\S+)\s*$/m', $output, $matches) !== 1) {
            return null;
        }

        // A selected path the panel does not recognise (a hand-built symlink, or a removed version that
        // was still the default) is null: naming another version would mark the wrong row as default.
        return $this->versionAt($matches[1]);
    }

    /** The installed version whose interpreter is exactly this path. */
    private function versionAt(string $binary): ?string
    {
        foreach ($this->versions->versions() as $version) {
            if ($this->binaryPath($version) === $binary) {
                return $version;
            }
        }

        return null;
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
     * Versions apt could install that are not installed already, read from the package index rather
     * than hardcoded: a server with the Ondřej archive sees the full range, one without sees what its
     * distribution ships.
     *
     * @return array<int, string>
     */
    public function installable(): array
    {
        $output = $this->serverOps->run(
            ['apt-cache', 'search', '--names-only', $this->stack->installablePattern()],
            ['feature' => 'runtime', 'op' => 'php_installable'],
        )->output();

        $installed = $this->versions->versions();

        $floor = $this->minimumOffered();

        return collect($this->stack->installableVersions($this->withoutPrereleases($output)))
            ->unique()
            ->reject(fn (string $version) => in_array($version, $installed, true))
            // Offered from `min_offered` up only; an older one already installed is not in this list
            // at all, so it keeps showing and working.
            ->reject(fn (string $version) => version_compare($version, $floor, '<'))
            ->sortByDesc(fn (string $version) => (float) $version)
            ->values()
            ->all();
    }

    /**
     * The oldest PHP offered for a new install (`SERVER_PHP_MIN_OFFERED`).
     */
    public function minimumOffered(): string
    {
        return (string) config('server.runtimes.php.min_offered', '7.4');
    }

    /**
     * The search output without the packages whose candidate is a pre-release.
     *
     * The package name does not show it (`php8.6-fpm` looked like `php8.5-fpm` while apt's candidate
     * was `8.6.0~beta3-...`), so a site could be put on a beta PHP unknowingly. `~` is Debian's marker
     * for "sorts before the release" (alpha, beta, RC, dev). `SERVER_PHP_OFFER_PRERELEASE` shows them.
     */
    private function withoutPrereleases(string $output): string
    {
        if ((bool) config('server.runtimes.php.offer_prerelease', false)) {
            return $output;
        }

        preg_match_all('/^(\S+)\s/m', $output, $matches);
        $packages = array_values(array_unique($matches[1] ?? []));

        if ($packages === []) {
            return $output;
        }

        $policy = $this->serverOps->run(
            ['apt-cache', 'policy', ...$packages],
            ['feature' => 'runtime', 'op' => 'php_installable_candidates'],
        );

        // Unreadable: keep the list rather than empty it. A missing filter
        // shows a beta; a failed one would offer nothing at all.
        if ($policy->failed()) {
            return $output;
        }

        preg_match_all('/^(\S+):\s*\n\s+Installed:.*\n\s+Candidate:\s*(\S+)/m', $policy->output(), $candidates, PREG_SET_ORDER);

        $prerelease = collect($candidates)
            ->filter(fn (array $c) => preg_match('/~(alpha|beta|rc|dev)/i', $c[2]) === 1)
            ->map(fn (array $c) => $c[1])
            ->all();

        return collect(explode("\n", $output))
            ->reject(fn (string $line) => in_array(strtok($line, " \t"), $prerelease, true))
            ->implode("\n");
    }

    public function installed(string $version): bool
    {
        return in_array($version, $this->versions->versions(), true);
    }

    /**
     * Base packages this version is meant to have and does not.
     *
     * "Installed" is not "usable": the `openlitespeed` package pulls in `lsphp83` as a dependency, so
     * a version nobody asked for shows as a bare interpreter (no curl, sqlite3, redis, intl, pgsql)
     * and looks healthy in the picker. A hand-installed `php8.1-fpm` is the same on the FPM stacks.
     *
     * Only packages the index actually has are reported: several base names do not exist on LSPHP
     * (LiteSpeed compiles them in). The order of the conditions keeps `apt-cache` off the common path.
     *
     * @return array<int, string>
     */
    public function missingBasePackages(string $version): array
    {
        if (! $this->installed($version)) {
            return [];
        }

        $packages = $this->stack->versionPackages($version);

        // The interpreter itself is what installed() means; its absence is a different state.
        array_shift($packages);

        $present = $this->installedPackages($packages);

        return array_values(array_filter(
            $packages,
            fn (string $package): bool => ! in_array($package, $present, true)
                && $this->packageExists($package),
        ));
    }

    /**
     * Which of these packages dpkg reports as installed.
     *
     * One call for the whole set, not one per package (see `PhpExtensionManager::packageModules()`).
     * dpkg-query exits non-zero when any name is unknown, which is the ordinary case here, so those
     * codes are expected and the output is read either way.
     *
     * @param  array<int, string>  $packages
     * @return array<int, string>
     */
    private function installedPackages(array $packages): array
    {
        if ($packages === []) {
            return [];
        }

        $result = $this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Package} ${Status}\n', ...$packages],
            ['feature' => 'runtime', 'op' => 'php_packages_installed', 'version' => ''],
            expectedExitCodes: [1, 2],
        );

        preg_match_all('/^(\S+) install ok installed$/m', $result->output(), $matches);

        return $matches[1] ?? [];
    }

    public function binaryPath(string $version): string
    {
        return $this->stack->binaryPath($version);
    }

    /**
     * The version the panel itself runs on.
     */
    public function panelVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /**
     * The base set, minus anything this server's package index does not have.
     *
     * Like `installable()` for versions: read the index rather than hardcode. apt fails the WHOLE
     * transaction on one unknown name (`E: Unable to locate package lsphp85-opcache`), so a single
     * missing extension took mysql, pgsql and curl with it and "install PHP 8.5" installed nothing.
     * A static list cannot be right: LiteSpeed's package set differs between versions (8.2-8.4 ship
     * 26 packages, 8.5 ships 25).
     *
     * The interpreter itself is never filtered: dropping it would turn "this version is unavailable"
     * into a successful install of nothing.
     *
     * @return array<int, string>
     */
    private function installablePackages(string $version): array
    {
        $packages = $this->stack->versionPackages($version);
        $interpreter = array_shift($packages);

        $available = array_values(array_filter(
            $packages,
            fn (string $package): bool => $this->packageExists($package),
        ));

        $missing = array_values(array_diff($packages, $available));

        // 🔴 Everything was dropped: not what this filter is for. It exists so one absent extension
        // cannot take the transaction down; losing all of them means the index lacks the repository, and
        // a bare interpreter would be reported as a successful install. Still not fatal (an unreadable
        // index must not mean "cannot be installed"); logged at warning to tell it from the ordinary case.
        if ($missing !== [] && $available === []) {
            Log::channel('server-ops')->warning('php.packages_index_empty', [
                'feature' => 'runtime',
                'op' => 'php_install',
                'version' => $version,
                'skipped' => $missing,
            ]);
        }

        if ($missing !== []) {
            // Recorded, not swallowed: "the panel quietly skipped opcache" must be answerable afterwards.
            Log::channel('server-ops')->info('php.packages_unavailable', [
                'feature' => 'runtime',
                'op' => 'php_install',
                'version' => $version,
                'skipped' => $missing,
            ]);
        }

        return [$interpreter, ...$available];
    }

    /**
     * Does the index have something installable under this name?
     *
     * `apt-cache policy`, not `show` or an exit code:
     *
     *   - `apt-cache policy <unknown>` prints nothing and exits **0**, so the exit status answers
     *     nothing.
     *   - `apt-cache show lsphp84-gd` **succeeds** for a package apt then refuses with "has no
     *     installation candidate".
     *
     * A `Candidate:` line that is not `(none)` is the only thing that distinguishes all three cases.
     */
    private function packageExists(string $package): bool
    {
        $result = $this->serverOps->run(
            ['apt-cache', 'policy', $package],
            ['feature' => 'runtime', 'op' => 'php_package_check', 'package' => $package],
            timeout: 30,
        );

        // Degrade to the old behaviour, not a worse one: if the check itself cannot run (apt lock,
        // broken index, no apt-cache), do not read "cannot confirm" as "not there". Stripping every
        // extension would install a bare interpreter and report success; passing the name through lets
        // apt decide and fail loudly.
        if ($result->failed()) {
            return true;
        }

        if (! preg_match('/^\s*Candidate:\s*(.+)$/m', $result->output(), $matches)) {
            return false;
        }

        return trim($matches[1]) !== '(none)';
    }

    /**
     * Install a version, with the extensions a site is unusable without (mysql, curl, mbstring...).
     * The base set is configurable, but not empty.
     *
     * @throws RuntimeInstallException
     */
    public function install(string $version, ?callable $onOutput = null): void
    {
        // Read before apt runs: see keepDefault().
        $previousDefault = $this->default();

        // 🔴 Before the existence checks, not after. `installablePackages()` asks `apt-cache policy`,
        // which reads the local index, not the repository. On a server whose index was never refreshed
        // since the LiteSpeed (or ondrej) list was added, every `lsphp83-*` answers "no", every extension
        // is skipped, and the install "succeeds" with a bare interpreter ("the list on disk is not the
        // index", as in MongoDbInstaller).
        //
        // Deliberately not fatal: `apt-get update` exits non-zero when any source fails, even one
        // unrelated to PHP, and that must not refuse the install. A stale index then behaves as before,
        // with a warning.
        $refreshed = $this->serverOps->apt(
            ['apt-get', 'update'],
            ['feature' => 'runtime', 'op' => 'php_index_refresh', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
        );

        if ($refreshed->failed()) {
            Log::channel('server-ops')->warning('php.index_refresh_failed', [
                'feature' => 'runtime',
                'op' => 'php_index_refresh',
                'version' => $version,
                'reference' => $refreshed->reference,
            ]);
        }

        $result = $this->serverOps->apt(
            ['apt-get', 'install', '-y', '--no-install-recommends', ...$this->installablePackages($version)],
            ['feature' => 'runtime', 'op' => 'php_install', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            // apt refuses to run unattended without this, and a prompt with
            // nobody to answer it hangs until the timeout.
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            // Passed through so the caller can report progress; what apt's output means is
            // InstallProgress's job.
            onOutput: $onOutput,
        );

        // Classified here, where the output still exists. Past this point only
        // the code travels — apt's stderr never leaves the server-ops log.
        if ($result->failed()) {
            throw new RuntimeInstallException(
                $result->reference,
                $this->classifier->classify('php', $result),
            );
        }

        $this->keepDefault($previousDefault, $version);

        // So the reloads every site change makes on this version finish the
        // requests in flight instead of answering them 502. Never fatal.
        $this->reloadGrace->apply($version);

        // PHP before 8.0 dies on a guessed zone that is not UTC; told the
        // server's zone explicitly from the start. Never fatal, like the above.
        if (LegacyPhpTimezone::needed($version)) {
            $this->legacyTimezone->apply($version, ServerTimezone::get());
        }
    }

    /**
     * Put the server default back if installing another version moved it.
     *
     * Debian keeps the `php` group in auto mode (highest priority wins) and a newer PHP has a higher
     * one, so installing 8.5 on a server defaulting to 8.4 silently made 8.5 the default for every
     * cron, shell `php` and new site. Setting it back also pins the group to manual mode.
     *
     * Never fatal: the install itself succeeded, and failing it would report the opposite.
     */
    private function keepDefault(?string $previous, string $installed): void
    {
        if ($previous === null || $previous === $installed || $this->default() === $previous) {
            return;
        }

        try {
            $this->setDefault($previous);
        } catch (Throwable $e) {
            Log::channel('server-ops')->warning('php.default_not_restored', [
                'feature' => 'runtime',
                'op' => 'php_default_restore',
                'version' => $previous,
                'installed' => $installed,
                'message' => $e->getMessage(),
            ]);
        }
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
        foreach ($this->stack->defaultCommands($version) as $step) {
            $result = $this->serverOps->run(
                $step['command'],
                ['feature' => 'runtime', 'op' => 'php_default_set', 'version' => $version],
            );

            if ($step['fatal']) {
                $this->must($result);

                continue;
            }

            // A secondary group the stack would like to move but can live without: recorded, not
            // raised (the interpreter did change).
            if ($result->failed()) {
                Log::warning('A secondary PHP alternative could not be moved.', [
                    'feature' => 'runtime',
                    'op' => 'php_default_set',
                    'version' => $version,
                    'reference' => $result->reference,
                ]);
            }
        }
    }

    /**
     * @throws SettingOperationException
     */
    public function uninstall(string $version, ?callable $onOutput = null): void
    {
        // Asked before the purge, while this version's PHP can still say where
        // its loader lives. Deleted only after the purge succeeds.
        $ionCube = $this->ionCube->panelFiles($version);

        $this->must($this->serverOps->apt(
            ['apt-get', 'purge', '-y', $this->stack->packagePrefix($version).'*'],
            ['feature' => 'runtime', 'op' => 'php_uninstall', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            onOutput: $onOutput,
        ));

        // Only after the purge succeeded, and only for a version detected on disk (the caller checks,
        // which keeps a client string out of this path). The purge cannot do it: the panel writes a
        // pool file per site into <version>/fpm/pool.d, dpkg does not own them, and a directory holding
        // unknown files survives, so the version stayed listed and the stale pools waited for a reinstall.
        $residual = $this->stack->residualDir($version);

        if ($residual !== null) {
            $this->serverOps->run(
                ['rm', '-rf', $residual],
                ['feature' => 'runtime', 'op' => 'php_uninstall_residual', 'version' => $version],
            );
        }

        // The panel's ionCube loader and ini, which dpkg never owned and so outlive the purge. On OLS the
        // ini sits in the version's scan dir: left there, a reinstall would load ionCube again unnoticed.
        // An ionCube from LiteSpeed's package went with the purge, and v7's php.ini line with the FPM dir.
        foreach ($ionCube as $path) {
            $this->files->delete($path, ['feature' => 'runtime', 'op' => 'php_uninstall_ioncube', 'version' => $version]);
        }

        // On OpenLiteSpeed dpkg kept the folders those two files were in, so the version's tree outlived
        // it as empty folders. Empty folders only: `find -empty` never removes a file or a non-empty one.
        $tree = $ionCube !== [] ? $this->stack->emptyFoldersDir($version) : null;

        if ($tree !== null) {
            $this->serverOps->run(
                ['find', $tree, '-depth', '-type', 'd', '-empty', '-delete'],
                ['feature' => 'runtime', 'op' => 'php_uninstall_empty_folders', 'version' => $version],
            );
        }
    }

    private function must(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }
}
