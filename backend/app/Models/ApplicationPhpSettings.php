<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id',
    'memory_limit', 'upload_max_filesize', 'post_max_size',
    'max_execution_time', 'max_input_time', 'max_input_vars', 'session_gc_maxlifetime',
    'pm_type', 'pm_max_children', 'pm_max_requests',
    'open_basedir_enabled', 'open_basedir_paths', 'disable_functions', 'allow_url_fopen',
    'php_timezone', 'auto_prepend_file', 'additional_directives',
])]
class ApplicationPhpSettings extends Model
{
    /** @var list<string> */
    public const PM_TYPES = ['ondemand', 'dynamic', 'static'];

    /**
     * Functions worth disabling by default. Every one of them is a way to run
     * a program from inside PHP, which is what a web shell needs and what a
     * normal application almost never does.
     */
    public const SAFE_DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec';

    /**
     * The cPanel/WHM-style hardening list: everything in SAFE, plus the
     * process, user and socket introspection an attacker uses *after* getting
     * code running. Opt-in, because that extra reach costs compatibility.
     *
     * Cleaned rather than copied verbatim from the list that circulates on
     * hosting forums, which contains:
     *
     *   - `posix,_getppid` — a stray comma splitting one name into two
     *     non-functions, so `posix_getppid` was never actually disabled.
     *   - `ini_alter` and `posix_geteuid` listed twice.
     *   - `leak` (gone since PHP 5.4), `source` and `listen` (never PHP
     *     functions), `virtual` (Apache mod_php only — a no-op under FPM).
     *
     * Deliberately NOT included, because each breaks working sites for very
     * little gain:
     *
     *   - `symlink` / `link` — Laravel's `storage:link`, Composer path repos.
     *   - `tmpfile` / `fpassthru` — PHPMailer, Guzzle streams, file downloads.
     *   - `diskfreespace` — WordPress Site Health, upload pre-checks.
     *   - `escapeshellcmd` — a *defensive* function; disabling it breaks the
     *     libraries that sanitise without preventing anything.
     *   - `stream_socket_server` — needed by the realtime layer.
     *
     * `ini_set` is not here either: disabling it breaks most applications, and
     * it could not undo this list anyway — the pool writes these as
     * `php_admin_value`, which a script cannot override at runtime.
     */
    public const STRICT_DISABLED_FUNCTIONS = 'getmyuid,passthru,shell_exec,dl,exec,system,highlight_file,show_source,'
        .'posix_ctermid,posix_getcwd,posix_getegid,posix_geteuid,posix_getgid,posix_getgrgid,posix_getgrnam,'
        .'posix_getgroups,posix_getlogin,posix_getpgid,posix_getpgrp,posix_getpid,posix_getppid,posix_getpwuid,'
        .'posix_getrlimit,posix_getsid,posix_getuid,posix_isatty,posix_kill,posix_mkfifo,posix_setegid,'
        .'posix_seteuid,posix_setgid,posix_setpgid,posix_setsid,posix_setuid,posix_times,posix_ttyname,posix_uname,'
        .'proc_open,proc_close,proc_nice,proc_terminate,ini_alter,popen,pcntl_exec,'
        .'socket_accept,socket_bind,socket_clear_error,socket_close,socket_connect,socket_listen,'
        .'socket_create_listen,socket_read,socket_create_pair';

    protected $table = 'application_php_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_execution_time' => 'integer',
            'max_input_time' => 'integer',
            'max_input_vars' => 'integer',
            'session_gc_maxlifetime' => 'integer',
            'pm_max_children' => 'integer',
            'pm_max_requests' => 'integer',
            'open_basedir_enabled' => 'boolean',
            'allow_url_fopen' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The values a site gets before anyone has chosen anything.
     *
     * Modest on purpose. A pool that is too small shows up as a slow site and
     * someone raises it; a pool that is too large shows up as the OOM killer
     * taking out a different site at 3am, and nobody connects the two.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'memory_limit' => '256M',
            'upload_max_filesize' => '64M',
            'post_max_size' => '64M',
            'max_execution_time' => 30,
            'max_input_time' => 60,
            'max_input_vars' => 1000,
            'session_gc_maxlifetime' => 1440,
            'pm_type' => 'ondemand',
            'pm_max_children' => 5,
            // Without this a slow leak grows a worker until the kernel kills
            // something. With it, the leak is bounded to 500 requests.
            'pm_max_requests' => 500,
            'open_basedir_enabled' => false,
            'open_basedir_paths' => null,
            'disable_functions' => null,
            'allow_url_fopen' => true,
            'php_timezone' => null,
            'auto_prepend_file' => null,
            'additional_directives' => null,
        ];
    }

    /**
     * Settings merged over the defaults — what the pool file is rendered from.
     *
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        $values = static::defaults();

        foreach (array_keys($values) as $key) {
            $set = $this->getAttribute($key);

            if ($set !== null && $set !== '') {
                $values[$key] = $set;
            }
        }

        return $values;
    }

    /**
     * Worst-case memory for this site: every worker running at its limit.
     *
     * The number every panel lets you set and none of them show you. It is an
     * upper bound rather than a prediction — but the upper bound is what the
     * OOM killer reacts to.
     */
    public function memoryCeilingBytes(): int
    {
        $effective = $this->effective();

        return self::toBytes((string) $effective['memory_limit']) * (int) $effective['pm_max_children'];
    }

    /** `256M` → bytes. `-1` (unlimited) is treated as 128M for budgeting. */
    public static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            // Unlimited cannot be budgeted, and treating it as zero would
            // report a server with unlimited pools as comfortably empty.
            return 128 * 1024 * 1024;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
