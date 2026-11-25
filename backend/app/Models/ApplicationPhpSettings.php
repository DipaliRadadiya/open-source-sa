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
    'pm_start_servers', 'pm_min_spare_servers', 'pm_max_spare_servers', 'pm_process_idle_timeout', 'pm_max_spawn_rate',
    'open_basedir_enabled', 'open_basedir_paths', 'disable_functions', 'allow_url_fopen',
    'php_timezone', 'auto_prepend_file', 'additional_directives',
])]
class ApplicationPhpSettings extends Model
{
    /** @var list<string> */
    public const PM_TYPES = ['ondemand', 'dynamic', 'static'];

    /** Disabled by default: each one runs a program from PHP, which a web shell needs and apps rarely do. */
    public const SAFE_DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec';

    /**
     * The cPanel/WHM-style hardening list: SAFE plus the process, user and socket introspection
     * used after code is already running. Opt-in, because it costs compatibility.
     *
     * Deliberately NOT included (each breaks working sites): `symlink`/`link` (storage:link,
     * Composer path repos), `tmpfile`/`fpassthru` (mailers, streams, downloads), `diskfreespace`,
     * `escapeshellcmd` (a defensive function), `stream_socket_server` (realtime layer), `ini_set`
     * (the pool writes these as `php_admin_value`, which a script cannot override anyway).
     */
    public const STRICT_DISABLED_FUNCTIONS = 'getmyuid,passthru,shell_exec,dl,exec,system,highlight_file,show_source,'
        .'posix_ctermid,posix_getcwd,posix_getegid,posix_geteuid,posix_getgid,posix_getgrgid,posix_getgrnam,'
        .'posix_getgroups,posix_getlogin,posix_getpgid,posix_getpgrp,posix_getpid,posix_getppid,posix_getpwuid,'
        .'posix_getrlimit,posix_getsid,posix_getuid,posix_isatty,posix_kill,posix_mkfifo,posix_setegid,'
        .'posix_seteuid,posix_setgid,posix_setpgid,posix_setsid,posix_setuid,posix_times,posix_ttyname,posix_uname,'
        .'proc_open,proc_close,proc_nice,proc_terminate,ini_alter,popen,pcntl_exec,'
        .'socket_accept,socket_bind,socket_clear_error,socket_close,socket_connect,socket_listen,'
        .'socket_create_listen,socket_read,socket_create_pair';

    /** The strict list for one site type. Nextcloud's cron and occ need `posix_getuid`/`posix_getpwuid`. */
    public static function strictDisabledFunctionsFor(string $siteType): string
    {
        $functions = explode(',', self::STRICT_DISABLED_FUNCTIONS);

        if ($siteType === 'nextcloud') {
            $functions = array_diff($functions, ['posix_getuid', 'posix_getpwuid']);
        }

        return implode(',', $functions);
    }

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
            'pm_start_servers' => 'integer',
            'pm_min_spare_servers' => 'integer',
            'pm_max_spare_servers' => 'integer',
            'pm_process_idle_timeout' => 'integer',
            'pm_max_spawn_rate' => 'integer',
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
     * Modest on purpose: an oversized pool ends in the OOM killer taking out a different site.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // Same as v7, so a migrated site is not capped lower than it was.
            'memory_limit' => '256M',
            'upload_max_filesize' => '128M',
            'post_max_size' => '128M',
            'max_execution_time' => 60,
            'max_input_time' => 60,
            'max_input_vars' => 1600,
            'session_gc_maxlifetime' => 1440,
            'pm_type' => 'ondemand',
            'pm_max_children' => 20,
            // Bounds a slow worker memory leak to 500 requests.
            'pm_max_requests' => 500,
            // Null: derived from pm_max_children when the pool is written
            // (PoolManager). Set only for a site that brought its own (v7).
            'pm_start_servers' => null,
            'pm_min_spare_servers' => null,
            'pm_max_spare_servers' => null,
            'pm_process_idle_timeout' => null,
            'pm_max_spawn_rate' => null,
            // On by default (as v7): the site folder, its sessions and /tmp ({@see PoolManager::openBasedir()}).
            'open_basedir_enabled' => true,
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

    /** Worst-case memory for this site: every worker running at its limit (what the OOM killer reacts to). */
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
            // Unlimited cannot be budgeted; zero would report the server as comfortably empty.
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
