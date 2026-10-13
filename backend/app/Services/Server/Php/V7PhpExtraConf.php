<?php

namespace App\Services\Server\Php;

use App\Models\Application;

/**
 * Turn a v7 site's "extra pool lines" (`extra_conf`) into v8 PHP settings.
 *
 * v7 pasted the text as-is at the end of the site's pool, after its own
 * fields — so a line there won over the field. Here every line lands where it
 * has the same effect in v8, or is refused with a reason:
 *
 * - a setting v8 has a field for (memory_limit, date.timezone, pm.*, ...) →
 *   that field, with v7's value (the extra line won in v7 too);
 * - a PHP setting or an allowed pool setting → additional_directives;
 * - anything else → refused, with the same reason the PHP screen gives.
 *
 * Nothing is saved here. The result is exactly the body of
 * `PUT /applications/{id}/php`, which tests the pool before reloading and
 * rolls back on failure (Dipali, 2026-10-13: one save path, not two).
 */
class V7PhpExtraConf
{
    /** v7 line name → v8 field, for settings v8 has a field for. */
    private const FIELDS = [
        'memory_limit' => 'memory_limit',
        'upload_max_filesize' => 'upload_max_filesize',
        'post_max_size' => 'post_max_size',
        'max_execution_time' => 'max_execution_time',
        'max_input_time' => 'max_input_time',
        'max_input_vars' => 'max_input_vars',
        'session.gc_maxlifetime' => 'session_gc_maxlifetime',
        'date.timezone' => 'php_timezone',
        'auto_prepend_file' => 'auto_prepend_file',
        'allow_url_fopen' => 'allow_url_fopen',
        'disable_functions' => 'disable_functions',
        'pm' => 'pm_type',
        'pm.max_children' => 'pm_max_children',
        'pm.max_requests' => 'pm_max_requests',
        'pm.start_servers' => 'pm_start_servers',
        'pm.min_spare_servers' => 'pm_min_spare_servers',
        'pm.max_spare_servers' => 'pm_max_spare_servers',
        'pm.process_idle_timeout' => 'pm_process_idle_timeout',
        'pm.max_spawn_rate' => 'pm_max_spawn_rate',
    ];

    private const INTEGERS = [
        'max_execution_time', 'max_input_time', 'max_input_vars', 'session_gc_maxlifetime',
        'pm_max_children', 'pm_max_requests', 'pm_start_servers', 'pm_min_spare_servers',
        'pm_max_spare_servers', 'pm_process_idle_timeout', 'pm_max_spawn_rate',
    ];

    public function __construct(private AdditionalDirectives $directives) {}

    /**
     * @return array{settings: array<string, mixed>, refused: array<int, array{line: string, reason: string, name: string}>}
     */
    public function translate(string $extraConf, Application $application, bool $fpm = true): array
    {
        $settings = [];
        $directives = [];
        $refused = [];
        $logs = rescue(fn () => $application->logsPath(), null, false);

        foreach (preg_split('/\r?\n/', $extraConf) ?: [] as $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = $this->nameAndValue($line);
            $field = $name === null ? null : (self::FIELDS[strtolower($name)] ?? null);

            if ($field !== null) {
                // Later lines win, as they did in v7's pool.
                $settings[$field] = $this->fieldValue($field, $value);

                continue;
            }

            $refusal = $this->directives->refusal($line, $fpm, $logs);

            if ($refusal !== null) {
                $refused[] = ['line' => $line, 'reason' => $refusal['reason'], 'name' => $refusal['name']];

                continue;
            }

            $directives[] = $line;
        }

        if ($directives !== []) {
            $settings['additional_directives'] = implode("\n", $directives);
        }

        return ['settings' => $settings, 'refused' => $refused];
    }

    /**
     * The setting a line sets, in either form: `name = v` or `php_*[name] = v`.
     *
     * @return array{0: ?string, 1: string}
     */
    private function nameAndValue(string $line): array
    {
        if (preg_match('/^php_(?:admin_)?(?:value|flag)\[([A-Za-z_][A-Za-z0-9_.]*)\]\s*=\s*(.*)$/', $line, $m) === 1
            || preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m) === 1) {
            return [$m[1], trim($m[2], " \t\"'")];
        }

        return [null, ''];
    }

    private function fieldValue(string $field, string $value): mixed
    {
        return match (true) {
            $field === 'allow_url_fopen' => in_array(strtolower($value), ['on', '1', 'true', 'yes'], true),
            // `10s` → 10: v7 wrote FPM's own format, v8 stores seconds.
            $field === 'pm_process_idle_timeout' => $this->seconds($value),
            in_array($field, self::INTEGERS, true) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => $value,
        };
    }

    private function seconds(string $value): mixed
    {
        if (preg_match('/^(\d+)([smhd]?)$/', $value, $m) !== 1) {
            return $value;
        }

        return (int) $m[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
    }
}
