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
 * On PHP-FPM these directives are carried by the pool file as `php_admin_value`. OpenLiteSpeed
 * has no pool (`lshttpd` spawns LSPHP itself), so without this the settings screen would store
 * the values and apply none of them.
 *
 * ## Why an .ini file rather than `phpIniOverride`
 *
 * OpenLiteSpeed's `phpIniOverride` block cannot set `disable_functions` (PHP honours it only
 * from a php.ini) and is unreliable for `memory_limit`. A real `.ini` honours every directive,
 * `open_basedir` and `disable_functions` included, so the site gets the enforcement the FPM
 * pool gives.
 *
 * ## How LSPHP is told to read it
 *
 * Each OLS site's own `extprocessor` block gets `env PHP_INI_SCAN_DIR`.
 *
 * **The leading colon in that value is load-bearing.** A blank entry means "also scan the
 * compiled-in `--with-config-file-scan-dir`"; without it the value replaces the default scan
 * directory, which holds every extension's `.ini`, and the site loses mysqli, curl and
 * opcache. Our directory is scanned last, so our values win (like `php_admin_value`).
 *
 * ## Where the file lives
 *
 * `{app root}/.panel/php/`: above the document root, so not a URL. `.panel` is root-owned and
 * never handed to the site user, so a site cannot rewrite its own limits (0755 directory, 0644
 * file, so LSPHP running as the site user can read it). A subdirectory of its own because PHP
 * scans a directory for every `.ini`, and `.panel` also holds the Basic Auth credential and
 * the WAF detect log.
 */
class SitePhpIni
{
    /**
     * `zz-` so alphabetical order puts it last: ours is the override.
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
     * Called from the OpenLiteSpeed driver's `apply()`, in the same pass that renders the vhost
     * naming it, so the file and the `env` line cannot drift apart.
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

        $sessions = $this->ensureSessionDirectory($application);

        if ($sessions !== null && $sessions->failed()) {
            return $sessions;
        }

        $errorLog = $this->ensureErrorLog($application);

        if ($errorLog !== null && $errorLog->failed()) {
            return $errorLog;
        }

        return $this->files->put(
            $this->path($application),
            $this->render($application, $settings ?? $this->settingsFor($application)),
            $context,
        );
    }

    /**
     * The PHP error log this file names, created so LSPHP can write to it.
     *
     * `logs/` is root-owned, group the site user, 0750 (see ApplicationLogDirectory): write
     * permission on a directory allows unlinking what is inside, so a user-owned log directory
     * would let a compromised site swap access.log for a symlink to /etc/cron.d. That model
     * assumes a root master opens the log (nginx, Apache, php-fpm); `lshttpd` spawns LSPHP as
     * the site user and hands it no descriptor, so PHP cannot open the log and falls back to
     * stderr (the error log viewer stays empty).
     *
     * Creating the FILE rather than loosening the DIRECTORY keeps both properties: the site can
     * write to a file it owns but cannot create, delete or replace anything in `logs/`.
     * Only on this stack: on FPM the master still opens the descriptor.
     */
    private function ensureErrorLog(Application $application): ?ServerOpsResult
    {
        $user = $application->systemUser?->username;

        if ($user === null) {
            return null;
        }

        $context = ['feature' => 'php', 'op' => 'site_php_error_log', 'application' => $application->id];
        $path = $this->pools->errorLogPath($application);

        // `touch` rather than a write: an existing log must keep its contents.
        $made = $this->serverOps->run(['touch', $path], $context, timeout: 15);

        if ($made->failed()) {
            return $made;
        }

        $owned = $this->serverOps->run(['chown', $user.':'.$user, $path], $context, timeout: 15);

        if ($owned->failed()) {
            return $owned;
        }

        // 0644, not 0600: the site owner must read it; the directory above decides who gets there.
        return $this->serverOps->run(['chmod', '0644', $path], $context, timeout: 15);
    }

    /**
     * The session directory this file's `session.save_path` names.
     *
     * On FPM the pool builder creates it (FPM refuses a pool whose session path is missing).
     * Here nothing refuses, so a missing directory kills every site at `session_start()`:
     * whoever writes the path creates it.
     *
     * ONLY THE SESSION DIRECTORY IS HANDED TO THE SITE, not `.panel` around it: the pool
     * builder's `chown -R` from `.panel` is harmless on FPM (limits live in /etc), but here the
     * limits live in `.panel/php/zz-panel.ini`, and a site that owned it could lift its own
     * `disable_functions` and `open_basedir`.
     */
    private function ensureSessionDirectory(Application $application): ?ServerOpsResult
    {
        $user = $application->systemUser?->username;

        if ($user === null) {
            return null;
        }

        $context = ['feature' => 'php', 'op' => 'site_sessions', 'application' => $application->id];
        $path = $this->pools->sessionPath($application);

        $made = $this->serverOps->run(['mkdir', '-p', $path], $context, timeout: 30);

        if ($made->failed()) {
            return $made;
        }

        // `-h`, and the chmod as the user: neither may follow a `sessions` replaced by a link
        // (as in PoolManager::ensureSiteDirectories()).
        $owned = $this->serverOps->run(['chown', '-h', $user.':'.$user, $path], $context, timeout: 30);

        if ($owned->failed()) {
            return $owned;
        }

        // 0700: session files are as sensitive as the cookies that name them.
        return $this->serverOps->run(['runuser', '-u', $user, '--', 'chmod', '0700', $path], $context, timeout: 15);
    }

    /**
     * Remove it, for a site moving onto a stack that carries these in a pool (left behind it
     * would be a second, invisible source of truth).
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
            // Both outside the document root and the same paths the pool uses, so a migrated
            // site keeps its sessions and log.
            'session.save_path = '.$this->pools->sessionPath($application),
            'session.gc_maxlifetime = '.(int) $effective['session_gc_maxlifetime'],
            'error_log = '.$this->pools->errorLogPath($application),
            'log_errors = On',
        ];

        // Only when set: an empty `date.timezone =` is an empty timezone, and PHP warns on
        // every date call.
        if (filled($effective['php_timezone'])) {
            $lines[] = 'date.timezone = '.$effective['php_timezone'];
        }

        if (filled($effective['auto_prepend_file'])) {
            $lines[] = 'auto_prepend_file = '.$effective['auto_prepend_file'];
        }

        // Asked of the pool builder, not recomputed, so both web servers enforce the same
        // base paths (site root, session directory, /tmp).
        if (($openBasedir = $this->pools->openBasedirFor($application, $settings)) !== null) {
            // Quoted: an unquoted ini value ends at a comment character, and a path is user input.
            $lines[] = 'open_basedir = "'.$openBasedir.'"';
        }

        if (filled($effective['disable_functions'])) {
            $lines[] = 'disable_functions = '.$effective['disable_functions'];
        }

        if (filled($effective['additional_directives'])) {
            $lines[] = '';
            $lines[] = '; Set by the site owner, appended verbatim — so a directive here';
            $lines[] = '; wins over everything above it.';
            $lines[] = app(AdditionalDirectives::class)->forIni((string) $effective['additional_directives']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Read from the database, never from an already-loaded relation: `apply()` runs after the
     * settings endpoint saves (via `PoolIsolator::republish()`), on an `Application` whose
     * `phpSettings` was loaded before the save, and would write the previous values.
     */
    private function settingsFor(Application $application): ApplicationPhpSettings
    {
        return ApplicationPhpSettings::query()->firstWhere('application_id', $application->id)
            ?? new ApplicationPhpSettings(['application_id' => $application->id]);
    }
}
