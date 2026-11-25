<?php

namespace App\Services\Server\Php;

/**
 * The site owner's "Additional directives": PHP settings only.
 *
 * One field, two destinations (a PHP-FPM pool file for nginx/Apache, the site's php.ini for
 * OpenLiteSpeed), parsed once and written in each one's form. Each line is `name = value` or the
 * FPM form `php_[admin_]value|flag[name] = value`. On FPM every line is written as
 * `php_admin_value[...]` / `php_admin_flag[...]`, so the field can only set PHP settings, never
 * a setting of the pool itself.
 *
 * Anything that is not a PHP setting line is refused when saved, and skipped (never written) on
 * every other path.
 *
 * Also refused when saved (still written if already saved):
 * - a setting the panel writes itself. PHP-FPM keeps the first of a repeated php_admin_value and
 *   the panel's lines come first, so the owner's line would be ignored on nginx/Apache but win
 *   on OpenLiteSpeed. One rule for every stack: set it on its own field.
 * - loading an extension, which has its own screen.
 *
 * On PHP-FPM only: a short list of pool settings that change how this site's own workers run
 * (`env[NAME]`, request timeouts, a slow log inside the site's logs, worker output, rlimits).
 * Pool settings that change the workers' user, listen address, PHP file extensions or the
 * panel-computed process manager are refused by name.
 */
class AdditionalDirectives
{
    private const FLAG_VALUES = ['on', 'off', 'true', 'false', 'yes', 'no', '0', '1'];

    /**
     * Every setting the pool template and SitePhpIni write. Keep in step with
     * both — a test renders each and compares.
     */
    public const PANEL_MANAGED = [
        'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time',
        'max_input_time', 'max_input_vars', 'allow_url_fopen', 'session.save_path',
        'session.gc_maxlifetime', 'error_log', 'log_errors', 'date.timezone',
        'auto_prepend_file', 'open_basedir', 'disable_functions',
    ];

    public const EXTENSION_LOADERS = ['extension', 'zend_extension'];

    /** Pool settings a site may set on its own pool. Value shape per name. */
    public const POOL_SETTINGS = [
        'request_terminate_timeout' => 'duration',
        'request_slowlog_timeout' => 'duration',
        'slowlog' => 'path',
        'catch_workers_output' => 'yesno',
        'decorate_workers_output' => 'yesno',
        'rlimit_files' => 'number',
        'rlimit_core' => 'core',
    ];

    /**
     * Pool settings refused by name: worker user, listen address, which files count as PHP
     * (a widened `security.limit_extensions` would run an upload as code), the pool's own
     * environment and logs, and the process manager (which has its own fields).
     */
    private const POOL_FORBIDDEN = '/^(user|group|listen(\..+)?|chroot|chdir|prefix|include|clear_env|access\..+|security\..+|process\..+|apparmor|ping\..+|request_terminate_timeout_track_finished)$/i';

    private const POOL_PM = '/^pm(\..+)?$/i';

    /**
     * @return array<int, array{name: string, value: string, flag: bool, admin: bool, pool: bool}>
     */
    public function parse(string $text): array
    {
        $settings = [];

        foreach ($this->lines($text) as $line) {
            $setting = $this->parseLine($line);

            if ($setting !== null) {
                $settings[] = $setting;
            }
        }

        return $settings;
    }

    /**
     * The first line that is neither a setting, a comment nor blank.
     */
    public function firstInvalidLine(string $text): ?string
    {
        foreach ($this->lines($text) as $line) {
            if ($this->parseLine($line) === null) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Why this text cannot be saved, or null: the first refused line, the translation key saying
     * why, and the setting's name.
     *
     * `$fpm` is false on OpenLiteSpeed (no pool). `$logsPath` is the site's own logs folder, the
     * only place a slow log may be written.
     *
     * @return array{reason: string, line: string, name: string}|null
     */
    public function refusal(string $text, bool $fpm = true, ?string $logsPath = null): ?array
    {
        foreach ($this->lines($text) as $line) {
            $key = $this->settingName($line);
            $setting = $this->parseLine($line);
            $name = strtolower($setting['name'] ?? '');

            $reason = match (true) {
                $key !== null && preg_match(self::POOL_PM, $key) === 1 => 'directive_pm',
                $key !== null && preg_match(self::POOL_FORBIDDEN, $key) === 1 => 'directive_pool_forbidden',
                $setting === null => 'directive_invalid',
                $setting['pool'] && ! $fpm => 'directive_fpm_only',
                $name === 'slowlog' && ! $this->insideLogs($setting['value'], $logsPath) => 'directive_slowlog_path',
                in_array($name, self::PANEL_MANAGED, true) => 'directive_managed',
                in_array($name, self::EXTENSION_LOADERS, true) => 'directive_extension',
                default => null,
            };

            if ($reason !== null) {
                return ['reason' => $reason, 'line' => $line, 'name' => $setting['name'] ?? (string) $key];
            }
        }

        return null;
    }

    /**
     * `$logsPath`: the site's own logs folder. A slow log anywhere else is never written (no
     * folder known means no slow log at all).
     */
    public function forFpm(string $text, ?string $logsPath = null): string
    {
        $settings = array_filter($this->parse($text), fn (array $s): bool => $s['name'] !== 'slowlog' || $this->insideLogs($s['value'], $logsPath));

        return implode("\n", array_map(fn (array $s): string => $this->fpmLine($s), $settings));
    }

    /**
     * Lines an older pool file held as the owner typed them; finding any means the file predates
     * this class.
     *
     * @return array<int, string>
     */
    public function legacyFpmLines(string $text): array
    {
        return array_values(array_filter($this->lines($text), function (string $line): bool {
            $setting = $this->parseLine($line);

            return $setting === null || $this->fpmLine($setting) !== $line;
        }));
    }

    public function forIni(string $text): string
    {
        // Pool settings are PHP-FPM's, not php.ini's: never written there.
        $settings = array_filter($this->parse($text), fn (array $s): bool => ! $s['pool']);

        return implode("\n", array_map(fn (array $s): string => "{$s['name']} = {$s['value']}", $settings));
    }

    /**
     * @param  array{name: string, value: string, flag: bool, admin: bool, pool: bool}  $setting
     */
    private function fpmLine(array $setting): string
    {
        if ($setting['pool']) {
            return "{$setting['name']} = {$setting['value']}";
        }

        $directive = ($setting['admin'] ? 'php_admin_' : 'php_').($setting['flag'] ? 'flag' : 'value');

        return "{$directive}[{$setting['name']}] = {$setting['value']}";
    }

    /**
     * Non-blank, non-comment lines, trimmed.
     *
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, ';') && ! str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array{name: string, value: string, flag: bool, admin: bool, pool: bool}|null
     */
    private function parseLine(string $line): ?array
    {
        $name = '[A-Za-z_][A-Za-z0-9_.]*';

        // Refused pool settings are not settings at all: never written.
        $key = $this->settingName($line);
        if ($key !== null && (preg_match(self::POOL_FORBIDDEN, $key) === 1 || preg_match(self::POOL_PM, $key) === 1)) {
            return null;
        }

        if (preg_match('/^env\[([A-Za-z_][A-Za-z0-9_]*)\]\s*=\s*(.*)$/', $line, $m) === 1) {
            return $this->oneLine(trim($m[2])) ? ['name' => "env[{$m[1]}]", 'value' => trim($m[2]), 'flag' => false, 'admin' => false, 'pool' => true] : null;
        }

        if (preg_match('/^([a-z_]+)\s*=\s*(.*)$/', $line, $m) === 1 && isset(self::POOL_SETTINGS[$m[1]])) {
            $value = trim($m[2]);

            return $this->poolValueValid(self::POOL_SETTINGS[$m[1]], $value)
                ? ['name' => $m[1], 'value' => $value, 'flag' => false, 'admin' => false, 'pool' => true]
                : null;
        }

        if (preg_match('/^php_(admin_)?(value|flag)\[('.$name.')\]\s*=\s*(.*)$/', $line, $m) === 1) {
            [$admin, $flag, $key, $value] = [$m[1] !== '', $m[2] === 'flag', $m[3], $m[4]];
        } elseif (preg_match('/^('.$name.')\s*=\s*(.*)$/', $line, $m) === 1) {
            [$key, $value] = [$m[1], $m[2]];
            $admin = true;
            $flag = in_array(strtolower(trim($value, " \"'")), self::FLAG_VALUES, true);
        } else {
            return null;
        }

        $value = trim($value);

        // One line, one value: nothing that could end the line or start a section.
        if (! $this->oneLine($value)) {
            return null;
        }

        return ['name' => $key, 'value' => $value, 'flag' => $flag, 'admin' => $admin, 'pool' => false];
    }

    private function oneLine(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F\[\]]/', $value) !== 1 || preg_match('/^"[^"\x00-\x1F\x7F]*"$/', $value) === 1;
    }

    /** The bare `name` of a `name = value` line, for refusing pool settings by name. */
    private function settingName(string $line): ?string
    {
        return preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) === 1 ? $m[1] : null;
    }

    private function poolValueValid(string $shape, string $value): bool
    {
        return match ($shape) {
            // PHP-FPM's own format: seconds, or a number with s/m/h/d; a day at most.
            'duration' => preg_match('/^(\d{1,6})([smhd]?)$/', $value, $m) === 1
                && (int) $m[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]] <= 86400,
            'path' => preg_match('#^/[A-Za-z0-9._/-]+$#', $value) === 1 && ! str_contains($value, '..'),
            'yesno' => in_array(strtolower($value), ['yes', 'no'], true),
            'number' => preg_match('/^\d{1,7}$/', $value) === 1,
            'core' => preg_match('/^(\d{1,10}|unlimited)$/', $value) === 1,
            default => false,
        };
    }

    private function insideLogs(string $path, ?string $logsPath): bool
    {
        return $logsPath !== null && $logsPath !== '' && str_starts_with($path, rtrim($logsPath, '/').'/');
    }
}
