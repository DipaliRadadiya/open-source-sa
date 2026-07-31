<?php

namespace App\Services\Server\Php\Stacks;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * LSPHP — how OpenLiteSpeed runs PHP.
 *
 * OLS cannot use PHP-FPM. It runs LSPHP over LSAPI, and almost every fact
 * differs: the packages are `lsphp84-…` (compact, no dot), the tree is under
 * `/usr/local/lsws` rather than `/etc/php`, the ini is the `litespeed` SAPI's,
 * and there is **no per-version service at all** — lshttpd spawns the LSPHP
 * processes itself, so applying a change means restarting the web server.
 *
 * ⚠️ **Unverified against a real OpenLiteSpeed server.** These paths and
 * package names come from LiteSpeed's documentation, not from a box this code
 * has run on — there was none available. Everything is therefore read from
 * `config/server.php`, so a wrong value is a config edit rather than a patch.
 * The first real OLS server is the test.
 */
class LsphpPhpStack implements PhpStack
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'lsphp';
    }

    /**
     * Detected from the lsws tree, newest first.
     *
     * From the directories rather than from a package list, same as FPM: a
     * version whose files are on disk is one the panel can act on, whatever
     * apt believes.
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

            if ($version !== null) {
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

    public function binaryPath(string $version): string
    {
        return $this->interpolate(
            (string) config('server.php_stacks.lsphp.binary_path', '{root}/lsphp{compact}/bin/php'),
            $version,
        );
    }

    /**
     * None, and that is the point.
     *
     * LSPHP processes belong to lshttpd, so there is no `php8.4-fpm` to start
     * or stop. Callers use this to decide whether PHP contributes rows to the
     * Services screen — on OLS it contributes none, rather than rows whose
     * buttons would do nothing.
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
     * Restart the web server, because that is what respawns LSPHP.
     *
     * Heavier than an FPM reload and unavoidable: there is no per-version
     * process to signal. `lswsctrl restart` is graceful — new requests go to
     * new workers while the old ones drain.
     */
    public function reload(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            (array) config('server.php_stacks.lsphp.reload_command', ['/usr/local/lsws/bin/lswsctrl', 'restart']),
            ['feature' => 'php', 'stack' => 'lsphp', 'op' => 'reload', 'version' => $version],
        );
    }

    /**
     * The nearest honest equivalent of `php-fpm -t`, which is not very near.
     *
     * There is no LSPHP command that validates a php.ini. `lswsctrl config_test`
     * checks the *web server's* configuration and would happily pass a php.ini
     * we had just broken, so using it here would be a check that lies.
     *
     * Instead the version's own binary is asked to start with that ini. It
     * catches an ini PHP cannot load at all, and misses subtler mistakes — a
     * weaker guarantee than FPM's, and stated as such rather than dressed up.
     */
    public function configTest(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->configTestCommand($version),
            ['feature' => 'php', 'stack' => 'lsphp', 'op' => 'config_test', 'version' => $version],
        );
    }

    /**
     * @return array<int, string>
     */
    public function configTestCommand(string $version): array
    {
        return [$this->binaryPath($version), '-c', $this->iniPath($version), '-v'];
    }

    /**
     * `lsphp84-`, not `lsphp8.4-` — LiteSpeed's packages drop the dot, and
     * assuming otherwise produces a "package not found" that looks like a
     * broken repository.
     */
    public function packagePrefix(string $version): string
    {
        return 'lsphp'.$this->compact($version).'-';
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

        // The interpreter package itself has no suffix: `lsphp84`, not
        // `lsphp84-lsphp`.
        return [
            'lsphp'.$this->compact($version),
            ...array_map(fn (string $package) => $this->extensionPackage($version, $package), $packages),
        ];
    }

    public function installablePattern(): string
    {
        return '^lsphp[0-9]+$';
    }

    public function installableMatcher(): string
    {
        return '/^lsphp(\d+)\s/m';
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
     * LSPHP writes into the web server's error log rather than one of its own,
     * so there is no per-version file to offer. Null keeps it off the Logs
     * screen instead of listing a path that will never exist.
     */
    public function logPath(string $version): ?string
    {
        return null;
    }

    /**
     * Refused, not silently ignored.
     *
     * `phpenmod` is Debian's and only understands `/etc/php`; pointed at the
     * lsws tree it exits zero having changed nothing. LiteSpeed ships no
     * equivalent, so until there is one the toggle says it cannot do this
     * rather than reporting a success that never happened.
     *
     * @return array<int, string>
     *
     * @throws PhpConfigException
     */
    public function extensionToggleCommand(string $version, string $extension, bool $enable): array
    {
        throw PhpConfigException::unsupportedOnStack($this->key());
    }

    /**
     * 8.4 → 84, the form LiteSpeed names everything after.
     */
    private function compact(string $version): string
    {
        return str_replace('.', '', $version);
    }

    /**
     * 84 → 8.4. Only ever applied to a directory name we globbed, so anything
     * that is not two-or-more digits is something else in the lsws tree and is
     * skipped rather than guessed at.
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
