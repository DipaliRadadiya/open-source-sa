<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

/**
 * A directory holding one symlink named `php`, pointing at a site's own interpreter, meant to go
 * first on `PATH`.
 *
 * A PHAR's shebang is `#!/usr/bin/env php`, so composer, wp-cli and every other PHAR run under
 * the *server default* PHP regardless of the site's version. Naming an interpreter explicitly
 * fixes the commands the panel writes, not a program that starts itself. Wrong-PHP composer runs
 * break sites (platform_check.php 500s) or resolve backwards (Craft 4 on a Craft 5 site).
 *
 * 🔴 **A directory, not `dirname($binary)`.** apt puts every PHP version in `/usr/bin` under a
 * versioned name, so prepending `/usr/bin` selects nothing; a directory with exactly one link
 * called `php` is the only shape that means "this version" to a shebang.
 *
 * Shared by `GitDeployer` and the installers: one rule, one place.
 *
 * Never fatal: every failure path returns null, and the caller then behaves as if no shim
 * existed. A missing PHP must not kill a deploy or install inside a helper.
 */
class PhpShim
{
    public function __construct(
        private ServerOps $serverOps,
        private PhpRuntime $php,
    ) {}

    /**
     * The shim directory for an application's PHP version, or null (carry on unshimmed).
     */
    public function forApplication(Application $application): ?string
    {
        $version = (string) $application->php_version;

        // Never interpolate an unvalidated version into a path.
        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return null;
        }

        $binary = $this->php->binaryPath($version);

        // A blank `php_binary_pattern` resolves to the bare `php` ("whatever is on PATH");
        // shimming that would point `php` at itself.
        if ($binary === '' || $binary === 'php') {
            return null;
        }

        return $this->ensure($version, $binary);
    }

    /**
     * `export PATH=…;` for a shell script, or an empty string (for scripts run through a shell).
     */
    public function exportFor(Application $application): string
    {
        $dir = $this->forApplication($application);

        return $dir === null ? '' : 'export PATH='.escapeshellarg($dir).':"$PATH"; ';
    }

    /**
     * `['env', 'PATH=…']` to prefix an argv, or an empty array (for commands run without a shell).
     *
     * `env` is already in the privileged binary allowlist, so no sudoers change is needed. No
     * shell expands `$PATH`, so the panel process's own PATH is appended explicitly.
     *
     * @return array<int, string>
     */
    public function envPrefixFor(Application $application): array
    {
        $dir = $this->forApplication($application);

        if ($dir === null) {
            return [];
        }

        $inherited = (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

        return ['env', 'PATH='.$dir.':'.$inherited];
    }

    /**
     * Create the directory and the link, or explain why not.
     *
     * The `test -x` guard matters: a dangling `php` symlink early on PATH would break work that
     * succeeds today.
     */
    private function ensure(string $version, string $binary): ?string
    {
        $base = rtrim((string) config('server.php_shim_dir', ''), '/');

        if ($base === '') {
            return null;
        }

        $dir = $base.'/'.$version;
        $link = $dir.'/php';

        $context = ['feature' => 'application', 'op' => 'php_shim'];

        if ($this->serverOps->run(['mkdir', '-p', '-m', '0755', $dir], $context)->failed()) {
            return $this->skip($version, 'shim directory could not be created');
        }

        // `-f` to replace, `-n` so a re-run does not create the link *inside*
        // the directory the old one points at.
        if ($this->serverOps->run(['ln', '-sfn', $binary, $link], $context)->failed()) {
            return $this->skip($version, 'shim could not be linked');
        }

        if ($this->serverOps->run(['test', '-x', $link], $context)->failed()) {
            return $this->skip($version, "no executable PHP at {$binary}");
        }

        return $dir;
    }

    private function skip(string $version, string $detail): ?string
    {
        Log::channel('server-ops')->warning('php shim unavailable, the default php will be used', [
            'feature' => 'application',
            'php_version' => $version,
            'detail' => $detail,
        ]);

        return null;
    }
}
