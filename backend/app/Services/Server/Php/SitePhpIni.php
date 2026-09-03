<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * A site's PHP settings as its own `.ini`, for the stack that has no pools.
 *
 * On PHP-FPM every one of these directives is carried by the pool file as a
 * `php_admin_value`. OpenLiteSpeed has no pool — `lshttpd` spawns LSPHP itself
 * — so until now the settings screen on an OLS server stored `memory_limit`,
 * `upload_max_filesize` and the rest and applied **none** of them: a 200, a
 * saved row, and a server that had not moved. The guard written to prevent
 * exactly that asked "does this stack have pools", which is false here, so it
 * never fired on the one stack it was needed for.
 *
 * ## Why an .ini file rather than `phpIniOverride`
 *
 * OpenLiteSpeed's vhost config has a `phpIniOverride` block that takes
 * `php_admin_value` lines, and it is the obvious answer. It is also the wrong
 * one: `disable_functions` cannot be set that way at all — PHP only honours it
 * from a php.ini, which the LiteSpeed maintainers say plainly ("it must be set
 * in php.ini … we cannot override via the php_admin_value") — and there are
 * repeated reports of `memory_limit` not taking either. Using it would have
 * shipped a fix that silently ignores part of what the user typed, which is
 * the bug this class exists to remove, moved somewhere harder to see.
 *
 * A real `.ini` honours every directive we write, `open_basedir` and
 * `disable_functions` included, so the OLS site ends up with the same
 * enforcement the FPM pool gives.
 *
 * ## How LSPHP is told to read it
 *
 * Each OLS site already has its own `extprocessor` block — its own socket, its
 * own `extUser`. The block gets `env PHP_INI_SCAN_DIR`, which is the mechanism
 * LiteSpeed's own documentation points panels at.
 *
 * **The leading colon in that value is load-bearing.** Per php.net, a blank
 * entry means "also scan the directory compiled in with
 * `--with-config-file-scan-dir`". Without it the value *replaces* the default
 * scan directory instead of adding to it — and that directory is where every
 * extension's `.ini` lives, so a bare path would take mysqli, curl and opcache
 * away from the site. It would not look like a settings bug; it would look
 * like PHP had been reinstalled wrong.
 *
 * Our directory is scanned last, so our values win over the defaults, which is
 * the same precedence `php_admin_value` has in a pool.
 *
 * ## Where the file lives
 *
 * `{app root}/.panel/php/` — inside the panel's own directory, which is above
 * the document root and therefore not a URL. `.panel` is root-owned and
 * deliberately never handed to the site user, so a site cannot rewrite the
 * limits imposed on it; it is 0755 and the file 0644, so the LSPHP process
 * running as that user can still read it.
 *
 * Its own subdirectory rather than `.panel` itself because PHP scans a
 * directory for **every** `.ini` in it. `.panel` also holds the Basic Auth
 * credential and the WAF detect log, and a scan directory has to contain
 * exactly what we mean it to contain, now and after somebody adds the next
 * file to `.panel`.
 */
class SitePhpIni
{
    /**
     * `zz-` so that alphabetical order puts it last if this directory ever
     * holds more than one file. Ours is the override; it goes on top.
     */
    private const FILE = 'zz-panel.ini';

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private PoolManager $pools,
    ) {}

    public function directory(Application $application): string
    {
        return $application->panelPath().'/php';
    }

    public function path(Application $application): string
    {
        return $this->directory($application).'/'.self::FILE;
    }

    /**
     * The value for `PHP_INI_SCAN_DIR`, default directory first.
     *
     * @see self for why the empty leading entry is not optional
     */
    public function scanDir(Application $application): string
    {
        return ':'.$this->directory($application);
    }

    /**
     * Write the site's `.ini`, creating its directory.
     *
     * Called from the OpenLiteSpeed driver's `apply()`, in the same pass that
     * renders the vhost naming it — the same arrangement as the log directory
     * and `.panel` itself. One writer means the file and the `env` line that
     * points at it cannot drift apart.
     */
    public function apply(Application $application, ?ApplicationPhpSettings $settings = null): ServerOpsResult
    {
        $context = ['feature' => 'php', 'op' => 'site_ini', 'application' => $application->id];

        $directory = $this->serverOps->run(
            ['mkdir', '-p', $this->directory($application)],
            $context,
            timeout: 30,
        );

        if ($directory->failed()) {
            return $directory;
        }

        return $this->files->put(
            $this->path($application),
            $this->render($application, $settings ?? $this->settingsFor($application)),
            $context,
        );
    }

    /**
     * Remove it again, for a site moving onto a stack that carries these in a
     * pool instead. Left behind it would be a second, invisible source of
     * truth for the same values.
     */
    public function remove(Application $application): ServerOpsResult
    {
        return $this->files->delete(
            $this->path($application),
            ['feature' => 'php', 'op' => 'site_ini_remove', 'application' => $application->id],
        );
    }

    public function render(Application $application, ApplicationPhpSettings $settings): string
    {
        $effective = $settings->effective();

        $lines = [
            '; Managed by the panel. Manual edits are overwritten on the next deploy.',
            '; The PHP settings for this site, as an additional ini file — the',
            '; OpenLiteSpeed equivalent of a php-fpm pool\'s php_admin_value lines.',
            '',
            'memory_limit = '.$effective['memory_limit'],
            'upload_max_filesize = '.$effective['upload_max_filesize'],
            'post_max_size = '.$effective['post_max_size'],
            'max_execution_time = '.(int) $effective['max_execution_time'],
            'max_input_time = '.(int) $effective['max_input_time'],
            'max_input_vars = '.(int) $effective['max_input_vars'],
            'allow_url_fopen = '.($effective['allow_url_fopen'] ? 'On' : 'Off'),
            '',
            // Both outside the document root, both the same paths the pool
            // uses, so a server migrated between web servers keeps its
            // sessions and keeps logging to the file the log viewer reads.
            'session.save_path = '.$this->pools->sessionPath($application),
            'session.gc_maxlifetime = '.(int) $effective['session_gc_maxlifetime'],
            'error_log = '.$this->pools->errorLogPath($application),
            'log_errors = On',
        ];

        // Only when set. `date.timezone =` with nothing after it is not "leave
        // it alone", it is an empty timezone, and PHP warns on every date call
        // for the rest of the request.
        if (filled($effective['php_timezone'])) {
            $lines[] = 'date.timezone = '.$effective['php_timezone'];
        }

        if (filled($effective['auto_prepend_file'])) {
            $lines[] = 'auto_prepend_file = '.$effective['auto_prepend_file'];
        }

        // Asked of the pool builder rather than recomputed. The base paths a
        // site cannot do without — its own root, its own session directory,
        // /tmp — are the panel's answer, not FPM's, and a second copy here is
        // how the two web servers would come to enforce different things.
        if (($openBasedir = $this->pools->openBasedirFor($application, $settings)) !== null) {
            // Quoted: the value is colon-separated paths, and an unquoted ini
            // value ends at a comment character. A path is user input.
            $lines[] = 'open_basedir = "'.$openBasedir.'"';
        }

        if (filled($effective['disable_functions'])) {
            $lines[] = 'disable_functions = '.$effective['disable_functions'];
        }

        if (filled($effective['additional_directives'])) {
            $lines[] = '';
            $lines[] = '; Set by the site owner, appended verbatim — so a directive here';
            $lines[] = '; wins over everything above it.';
            $lines[] = trim((string) $effective['additional_directives']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Read from the database, never from an already-loaded relation.
     *
     * This is written from inside `apply()`, which the settings endpoint calls
     * *after* saving — through `PoolIsolator::republish()`, several layers
     * down, on an `Application` whose `phpSettings` relation was loaded before
     * the save. Trusting that relation wrote the file from the values the site
     * had a moment ago: the request returned 200, the row held 512M, and the
     * ini on the server said 256M. Exactly the class of bug this whole file
     * exists to remove, so it does not get to reappear one layer lower.
     */
    private function settingsFor(Application $application): ApplicationPhpSettings
    {
        return ApplicationPhpSettings::query()->firstWhere('application_id', $application->id)
            ?? new ApplicationPhpSettings(['application_id' => $application->id]);
    }
}
