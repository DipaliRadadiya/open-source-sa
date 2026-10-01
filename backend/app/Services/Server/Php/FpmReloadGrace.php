<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Services\Server\ManagedFile;
use Illuminate\Support\Facades\Log;

/**
 * Let a PHP-FPM reload finish the requests it interrupts.
 *
 * The panel reloads a version's FPM on every site created or deleted and
 * every PHP setting saved. `process_control_timeout` is how long a reload
 * waits for workers to finish what they are serving, and it ships at 0 —
 * so every reload killed every request in flight on every site using that
 * version, and the visitor got a 502. Measured on the nginx test server
 * (2026-10-01): a six-second request with a reload in the middle answered
 * 502; with this file in place, 200. OpenLiteSpeed restarts LSPHP
 * gracefully on its own (measured the same day) and has no FPM, so this
 * does nothing there.
 *
 * A file of its own in pool.d, not an edit to php-fpm.conf: the distro's
 * conffile stays untouched, so a package upgrade neither prompts about it nor
 * puts the 0 back. FPM reads `[global]` from any included file (checked with
 * `php-fpm8.4 -tt`). The dot in the name is what keeps it apart from site
 * pools: a site's pool is named after its slug, and a slug has no dots.
 *
 * install.sh writes the same file for the version it installs; this covers
 * every version installed from the panel afterwards.
 */
class FpmReloadGrace
{
    public const FILE = '00.panel-global.conf';

    public const TIMEOUT = '30s';

    public function __construct(
        private PhpStack $stack,
        private ManagedFile $files,
    ) {}

    public static function contents(): string
    {
        return "; Managed by the panel. Lets a reload finish the requests it would otherwise cut off.\n"
            ."[global]\n"
            .'process_control_timeout = '.self::TIMEOUT."\n";
    }

    public function path(string $version): string
    {
        return rtrim((string) config('server.php_dir', '/etc/php'), '/')."/{$version}/fpm/pool.d/".self::FILE;
    }

    /**
     * Write the file and reload. Never fatal: a server without it is the
     * server as it was. A file FPM refuses is taken out again before
     * anything reloads, because a config FPM cannot parse stops the whole
     * daemon at its next restart, not just one site.
     */
    public function apply(string $version): bool
    {
        if ($this->stack->key() !== 'fpm') {
            return false;
        }

        $context = ['feature' => 'php', 'op' => 'reload_grace', 'version' => $version];
        $path = $this->path($version);

        if ($this->files->put($path, self::contents(), $context)->failed()) {
            return false;
        }

        $test = $this->stack->configTest($version);

        if ($test->failed()) {
            $this->files->delete($path, $context);
            Log::channel('server-ops')->warning('php.reload_grace_refused', $context + ['reference' => $test->reference]);

            return false;
        }

        return $this->stack->reload($version)->ok;
    }
}
