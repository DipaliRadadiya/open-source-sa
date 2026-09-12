<?php

namespace App\Services\Server\Runtimes;

use App\Contracts\PhpStack;
use App\Contracts\Runtime;
use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;

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
        private PhpStack $stack,
        private InstallFailureClassifier $classifier,
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
     *
     * The path is matched against the stack's own `binaryPath()` per installed
     * version rather than having a version read out of it by pattern. The
     * pattern here was `\S*php(\d+\.\d+)` — true of `/usr/bin/php8.4` and false
     * of every LSPHP path, because LiteSpeed names its tree `lsphp85` with no
     * dot and ends the path in a bare `php`. So an OLS box reported "no
     * default" whatever was actually selected, and only the stack knows what
     * its own paths look like.
     */
    public function default(): ?string
    {
        $output = $this->serverOps->run(
            ['update-alternatives', '--query', 'php'],
            ['feature' => 'runtime', 'op' => 'php_default'],
        )->output();

        if (preg_match('/^Value:\s*(\S+)\s*$/m', $output, $matches) !== 1) {
            return null;
        }

        $value = $matches[1];

        foreach ($this->versions->versions() as $version) {
            if ($this->binaryPath($version) === $value) {
                return $version;
            }
        }

        // A selected path the panel does not recognise — a hand-built symlink,
        // or a version removed while it was still the default. Null says "this
        // is not one of the versions listed above", which is the truth; naming
        // one of them anyway would mark the wrong row as default.
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
            ['apt-cache', 'search', '--names-only', $this->stack->installablePattern()],
            ['feature' => 'runtime', 'op' => 'php_installable'],
        )->output();

        $installed = $this->versions->versions();

        return collect($this->stack->installableVersions($output))
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
     * The same principle `installable()` states for versions — read the index
     * rather than hardcode, because that is the truth in both cases — applied
     * to the packages themselves. It was not, and the difference cost two
     * separate failures:
     *
     *   E: Unable to locate package lsphp84-mbstring   (the FPM names)
     *   E: Unable to locate package lsphp85-opcache    (a per-version gap)
     *
     * apt fails the WHOLE transaction on one unknown name, so a single missing
     * extension took mysql, pgsql and curl down with it and "install PHP 8.5"
     * installed nothing at all.
     *
     * A static list cannot be right here. LiteSpeed's package set differs
     * between versions — 8.2, 8.3 and 8.4 ship 26 packages and 8.5 ships 25 —
     * so any list correct for one version is wrong for another, and correcting
     * it for 8.5 today would break on 8.6.
     *
     * **The interpreter itself is never filtered.** Dropping `lsphp85` because
     * the index does not have it would turn "this version is unavailable" into
     * a successful install of nothing. Extensions are degradable; the thing
     * being installed is not.
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

        if ($missing !== []) {
            // Recorded, not swallowed. A site missing an extension it expected
            // is a real difference in what it can run, and "the panel quietly
            // decided not to install opcache" must be answerable afterwards.
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
     * `apt-cache policy` rather than `show` or an exit code, and both halves
     * of that are learned from a real failure:
     *
     *   - `apt-cache policy <unknown>` prints nothing and exits **0**, so the
     *     exit status answers nothing.
     *   - `apt-cache show lsphp84-gd` **succeeds** for a package apt then
     *     refuses with "has no installation candidate" — a name the index
     *     knows and cannot install. It looks real in a search and still kills
     *     the transaction.
     *
     * A `Candidate:` line that is not `(none)` is the only thing that
     * distinguishes all three cases.
     */
    private function packageExists(string $package): bool
    {
        $result = $this->serverOps->run(
            ['apt-cache', 'policy', $package],
            ['feature' => 'runtime', 'op' => 'php_package_check', 'package' => $package],
            timeout: 30,
        );

        // DEGRADE TO THE OLD BEHAVIOUR, NOT TO A WORSE ONE.
        //
        // If the check itself could not run -- apt lock, a broken index, no
        // apt-cache at all -- this must not read "cannot confirm" as "not
        // there". Stripping every extension on a failed lookup would install
        // a bare interpreter and report success, which is the same silent
        // half-install this filter exists to prevent, wearing a new hat.
        //
        // Passing the name through instead puts us exactly where we were
        // before this method existed: apt decides, and if the package really
        // is missing it says so loudly. A filter that cannot see is a filter
        // that should not filter.
        if ($result->failed()) {
            return true;
        }

        if (! preg_match('/^\s*Candidate:\s*(.+)$/m', $result->output(), $matches)) {
            return false;
        }

        return trim($matches[1]) !== '(none)';
    }

    /**
     * Install a version, with the extensions a site is unusable without.
     *
     * A bare `phpX.Y-fpm` has no mysql, no curl, no mbstring — every
     * application in the marketplace would fail on it. The base set is
     * configurable rather than assumed, but it is not empty.
     *
     * @throws RuntimeInstallException
     */
    public function install(string $version, ?callable $onOutput = null): void
    {
        $result = $this->serverOps->apt(
            ['apt-get', 'install', '-y', '--no-install-recommends', ...$this->installablePackages($version)],
            ['feature' => 'runtime', 'op' => 'php_install', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            // apt refuses to run unattended without this, and a prompt with
            // nobody to answer it hangs until the timeout.
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            // Passed through so the caller can report progress while this runs.
            // Nothing here interprets it: what apt's output *means* is
            // InstallProgress's job, and this method should not grow a second
            // one.
            onOutput: $onOutput,
        );

        // Classified here, where the output still exists. Past this point only
        // the code travels — apt's stderr never leaves the server-ops log.
        if ($result->failed()) {
            throw new RuntimeInstallException(
                $result->reference,
                $this->classifier->classify('php', $result->output()),
            );
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

            // A secondary group the stack would like to move but can live
            // without. Recorded rather than raised: the interpreter did change,
            // and reporting that as a failure would tell the user the opposite
            // of what happened.
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
        $this->must($this->serverOps->apt(
            ['apt-get', 'purge', '-y', $this->stack->packagePrefix($version).'*'],
            ['feature' => 'runtime', 'op' => 'php_uninstall', 'version' => $version],
            timeout: (int) config('server.runtimes.php.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            onOutput: $onOutput,
        ));

        // Only after the purge succeeded, and only for a version that was
        // detected on disk to begin with — the caller checks that, which is
        // what keeps a client string out of this path.
        //
        // The purge cannot do this itself: the panel writes a pool file per
        // site into <version>/fpm/pool.d, dpkg does not own them, and a
        // directory still holding unknown files survives. The version then
        // went on being listed after it was removed, because detection reads
        // exactly these directories — and the stale pools sat there waiting
        // for the next install of that version to pick them up, referring to
        // sites that may no longer exist.
        $residual = $this->stack->residualDir($version);

        if ($residual !== null) {
            $this->serverOps->run(
                ['rm', '-rf', $residual],
                ['feature' => 'runtime', 'op' => 'php_uninstall_residual', 'version' => $version],
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
