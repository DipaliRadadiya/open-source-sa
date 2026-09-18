<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

/**
 * A directory holding one symlink named `php`, pointing at a site's own
 * interpreter, meant to go first on `PATH`.
 *
 * ## Why this exists at all
 *
 * A PHAR's shebang is `#!/usr/bin/env php`. The kernel reads that line and
 * looks up `php` on `PATH` — so composer, wp-cli and every other PHAR run
 * under whatever the *server default* happens to be, regardless of which
 * version the site is set to. Naming an interpreter explicitly fixes the
 * commands the panel writes; it does nothing for a program that starts itself.
 *
 * 🔴 **A directory, not `dirname($binary)`.** fnm gives each Node version its
 * own `bin`, so Node can prepend the real directory. PHP cannot: apt puts
 * every version in `/usr/bin` under a versioned name, so prepending
 * `/usr/bin` selects nothing. A directory containing exactly one link called
 * `php` is the only shape that means "this version" to a shebang.
 *
 * ## Why it is shared
 *
 * Extracted from `GitDeployer`, which built it on 2026-09-17 after a site
 * pinned to 8.2 on a box defaulting to 8.4 had `composer install` resolve
 * against 8.4 — composer writes what it resolved against into
 * `vendor/composer/platform_check.php`, `vendor/autoload.php` requires it, and
 * the site answered every request with a 500 from composer's own guard.
 *
 * The installer needs the identical thing for the identical reason:
 * `composer create-project craftcms/craft` under the wrong PHP does not fail,
 * it **resolves backwards** and installs Craft 4 on a site the panel reports
 * as Craft 5. Copying the helper rather than sharing it is how this panel has
 * repeatedly ended up with one rule maintained in two places and edited in
 * one — three separate instances of this same PHP-resolution bug were found in
 * two days.
 *
 * ## Never fatal
 *
 * Every failure path returns null, and null means the caller behaves exactly
 * as it did before a shim existed. A site whose chosen PHP has since been
 * uninstalled must not have its deploy or install die inside a helper, in a
 * step the user cannot map to anything they did.
 */
class PhpShim
{
    public function __construct(
        private ServerOps $serverOps,
        private PhpRuntime $php,
    ) {}

    /**
     * The shim directory for an application's PHP version, or null.
     *
     * Null when there is nothing sensible to shim — not a failure the caller
     * should report, just an instruction to carry on unshimmed.
     */
    public function forApplication(Application $application): ?string
    {
        $version = (string) $application->php_version;

        // Not a version we are willing to interpolate into a path. Only ever
        // set from validated input, so this guards a future caller rather than
        // today's.
        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return null;
        }

        $binary = $this->php->binaryPath($version);

        // A blank `php_binary_pattern` resolves to the bare name `php`, which
        // is the operator saying "whatever is on PATH". Shimming that would
        // point `php` at itself and override a deliberate choice.
        if ($binary === '' || $binary === 'php') {
            return null;
        }

        return $this->ensure($version, $binary);
    }

    /**
     * `export PATH=…;` for a shell script, or an empty string.
     *
     * The shape `GitDeployer` needs: its script is assembled as text and run
     * through a shell, so the prefix is a statement rather than an argument.
     */
    public function exportFor(Application $application): string
    {
        $dir = $this->forApplication($application);

        return $dir === null ? '' : 'export PATH='.escapeshellarg($dir).':"$PATH"; ';
    }

    /**
     * `['env', 'PATH=…']` to prefix an argv, or an empty array.
     *
     * The shape the installers need: their commands are argument arrays run
     * without a shell, so there is nowhere to put an `export`. `env` is
     * already in the privileged binary allowlist beside `runuser`, so this
     * adds no sudoers grant and leaves the deploy runbook unchanged.
     *
     * `$PATH` is not interpolated by a shell here, so the inherited PATH is
     * appended explicitly from the value the panel process itself has.
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
     * The `test -x` is the guard that matters: a dangling symlink named `php`
     * early on PATH would break work that succeeds today, which is the one
     * outcome this must never produce.
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
