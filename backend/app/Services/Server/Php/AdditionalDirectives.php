<?php

namespace App\Services\Server\Php;

/**
 * The site owner's "Additional directives": PHP settings only.
 *
 * One field, two destinations — a PHP-FPM pool file (nginx, Apache) and the
 * site's php.ini (OpenLiteSpeed) — so it is parsed once and written in each
 * one's form. Each line is a PHP setting, `name = value`, or the FPM form
 * `php_[admin_]value|flag[name] = value`. On FPM every line is written as
 * `php_admin_value[...]` / `php_admin_flag[...]`, so the field can only ever
 * set a PHP setting, never a setting of the pool itself.
 *
 * Anything that is not a PHP setting line is refused when saved, and skipped
 * — never written — on every other path (2026-09-30).
 *
 * Also refused when saved (2026-10-01), still written if already saved:
 * - a setting the panel writes itself. PHP-FPM keeps the first of a repeated
 *   php_admin_value, and the panel's lines come first, so on nginx/Apache the
 *   owner's line was silently ignored while on OpenLiteSpeed it won. One rule
 *   for every stack: set it on its own field.
 * - loading an extension, which has its own screen.
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

    /**
     * @return array<int, array{name: string, value: string, flag: bool, admin: bool}>
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
     * Why this text cannot be saved, or null: the first refused line, the
     * translation key saying why, and the setting's name.
     *
     * @return array{reason: string, line: string, name: string}|null
     */
    public function refusal(string $text): ?array
    {
        foreach ($this->lines($text) as $line) {
            $setting = $this->parseLine($line);
            $name = strtolower($setting['name'] ?? '');

            $reason = match (true) {
                $setting === null => 'directive_invalid',
                in_array($name, self::PANEL_MANAGED, true) => 'directive_managed',
                in_array($name, self::EXTENSION_LOADERS, true) => 'directive_extension',
                default => null,
            };

            if ($reason !== null) {
                return ['reason' => $reason, 'line' => $line, 'name' => $setting['name'] ?? ''];
            }
        }

        return null;
    }

    public function forFpm(string $text): string
    {
        return implode("\n", array_map(fn (array $s): string => $this->fpmLine($s), $this->parse($text)));
    }

    /**
     * Lines a pool written before 2026-09-30 held as the owner typed them,
     * and one written today does not — so finding any of them in a pool file
     * means that file still predates this class.
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
        return implode("\n", array_map(fn (array $s): string => "{$s['name']} = {$s['value']}", $this->parse($text)));
    }

    /**
     * @param  array{name: string, value: string, flag: bool, admin: bool}  $setting
     */
    private function fpmLine(array $setting): string
    {
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
     * @return array{name: string, value: string, flag: bool, admin: bool}|null
     */
    private function parseLine(string $line): ?array
    {
        $name = '[A-Za-z_][A-Za-z0-9_.]*';

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

        // One line, one value: nothing that could end the line or start a
        // section in either file.
        if (preg_match('/[\x00-\x1F\x7F\[\]]/', $value) === 1 && ! preg_match('/^"[^"\x00-\x1F\x7F]*"$/', $value)) {
            return null;
        }

        return ['name' => $key, 'value' => $value, 'flag' => $flag, 'admin' => $admin];
    }
}
