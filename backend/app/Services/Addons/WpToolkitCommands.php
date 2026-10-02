<?php

namespace App\Services\Addons;

use InvalidArgumentException;

/**
 * Every wp-toolkit command Central can reach, and nothing else.
 *
 * One entry per route: the validation its input gets (applied by
 * WordPressAddonRequest), the exact argument list it becomes, whether it runs
 * in the request or is queued, and whether it changes the site (and so is
 * written to the activity log). Kept as a table rather than forty controller
 * methods because the forty would be the same five lines, and the table is
 * what a reviewer needs to answer "what can Central make this server run".
 *
 * `async` commands can outlast a request — downloads, updates, whole-database
 * scans — and answer 202 with an AddonRun to poll.
 */
final class WpToolkitCommands
{
    /** A plugin/theme slug as the WordPress directory spells them. */
    public const SLUG = '[A-Za-z0-9][A-Za-z0-9._-]*';

    /**
     * @return array<string, array{rules: array<string, mixed>, argv: callable(array<string, mixed>, array<string, string>): array<int, string>, async: bool, mutates: bool, rules_target?: bool}>
     */
    public static function all(): array
    {
        $source = ['required', 'string', 'max:2048', 'regex:/^('.self::SLUG.'|https:\/\/[^\s]+)$/'];
        $version = ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9][0-9A-Za-z.\-]*$/'];
        $flag = fn (bool $on, string $name): array => $on ? [$name] : [];

        return [
            // ── plugins ──────────────────────────────────────────────────────
            'plugins.list' => self::read(fn () => ['plugin', 'list']),
            'plugins.install' => [
                'rules' => ['plugin' => $source, 'activate' => ['sometimes', 'boolean'], 'version' => $version],
                'argv' => fn ($in) => ['plugin', 'install', $in['plugin'], ...$flag((bool) ($in['activate'] ?? false), '--activate'), ...(filled($in['version'] ?? null) ? ['--plugin-version='.$in['version']] : [])],
                'async' => true, 'mutates' => true,
            ],
            'plugins.uninstall' => [
                'rules' => ['keep_files' => ['sometimes', 'boolean']],
                'argv' => fn ($in, $p) => ['plugin', 'uninstall', $p['slug'], ...$flag((bool) ($in['keep_files'] ?? false), '--keep-files')],
                'async' => false, 'mutates' => true,
            ],
            'plugins.toggle' => [
                'rules' => ['action' => ['required', 'in:activate,deactivate']],
                'argv' => fn ($in, $p) => ['plugin', 'toggle', $p['slug'], $in['action']],
                'async' => false, 'mutates' => true,
            ],
            'plugins.update' => self::write(fn ($in, $p) => ['plugin', 'update', $p['slug']], async: true),
            'plugins.update-all' => self::write(fn () => ['plugin', 'update-all'], async: true),

            // ── themes ───────────────────────────────────────────────────────
            'themes.list' => self::read(fn () => ['theme', 'list']),
            'themes.install' => [
                'rules' => ['theme' => $source, 'activate' => ['sometimes', 'boolean'], 'version' => $version],
                'argv' => fn ($in) => ['theme', 'install', $in['theme'], ...$flag((bool) ($in['activate'] ?? false), '--activate'), ...(filled($in['version'] ?? null) ? ['--theme-version='.$in['version']] : [])],
                'async' => true, 'mutates' => true,
            ],
            'themes.uninstall' => self::write(fn ($in, $p) => ['theme', 'uninstall', $p['slug']]),
            'themes.activate' => self::write(fn ($in, $p) => ['theme', 'activate', $p['slug']]),
            'themes.update' => self::write(fn ($in, $p) => ['theme', 'update', $p['slug']], async: true),
            'themes.update-all' => self::write(fn () => ['theme', 'update-all'], async: true),

            // ── core ─────────────────────────────────────────────────────────
            'core.version' => self::read(fn () => ['core', 'version']),
            'core.update' => [
                'rules' => ['version' => $version, 'minor' => ['sometimes', 'boolean']],
                'argv' => fn ($in) => ['core', 'update', ...(filled($in['version'] ?? null) ? ['--core-version='.$in['version']] : []), ...$flag((bool) ($in['minor'] ?? false), '--minor')],
                'async' => true, 'mutates' => true,
            ],
            'core.update-db' => self::write(fn () => ['core', 'update-db'], async: true),
            'core.verify-checksums' => ['rules' => [], 'argv' => fn () => ['core', 'verify-checksums'], 'async' => true, 'mutates' => false],

            // ── summaries ────────────────────────────────────────────────────
            'summary' => self::read(fn ($in, $p) => ['summary', $p['part']]),

            // ── tools ────────────────────────────────────────────────────────
            'search-replace' => [
                'rules' => [
                    'search' => ['required', 'string', 'max:2048', 'not_regex:/^--/'],
                    'replace' => ['present', 'nullable', 'string', 'max:2048', 'not_regex:/^--/', 'different:search'],
                    'dry_run' => ['sometimes', 'boolean'],
                    'include_guid' => ['sometimes', 'boolean'],
                ],
                // Flags first, then `--`: after it the toolkit reads both values
                // as values, so a search term that begins with a dash cannot be
                // taken for a flag.
                'argv' => fn ($in) => ['search-replace', ...$flag((bool) ($in['dry_run'] ?? false), '--dry-run'), ...$flag((bool) ($in['include_guid'] ?? false), '--include-guid'), '--', $in['search'], (string) ($in['replace'] ?? '')],
                // A dry run still reads every table; on a large site that is
                // as slow as the real thing.
                'async' => true, 'mutates' => true,
            ],
            'rewrite.flush' => [
                'rules' => ['hard' => ['sometimes', 'boolean']],
                'argv' => fn ($in) => ['rewrite', 'flush', ...$flag((bool) ($in['hard'] ?? false), '--hard')],
                'async' => false, 'mutates' => true,
            ],
            'cache.flush' => self::write(fn () => ['cache', 'flush']),
            'cron.run' => [
                'rules' => ['all' => ['sometimes', 'boolean']],
                'argv' => fn ($in) => ['cron', 'run', ...$flag((bool) ($in['all'] ?? false), '--all')],
                'async' => true, 'mutates' => true,
            ],
            'cron.set' => [
                'rules' => ['enabled' => ['required', 'boolean']],
                'argv' => fn ($in) => ['cron', (bool) $in['enabled'] ? 'enable' : 'disable'],
                'async' => false, 'mutates' => true,
            ],

            // ── configuration ────────────────────────────────────────────────
            'debug.get' => self::read(fn () => ['debug', 'get']),
            'debug.set' => [
                'rules' => ['setting' => ['required', 'in:WP_DEBUG,WP_DEBUG_LOG,WP_DEBUG_DISPLAY'], 'value' => ['required', 'boolean']],
                'argv' => fn ($in) => ['debug', 'set', $in['setting'], (bool) $in['value'] ? 'true' : 'false'],
                'async' => false, 'mutates' => true,
            ],
            'settings.get' => self::read(fn () => ['settings', 'get']),
            'settings.set' => [
                // Shape only; the toolkit checks each value against what the
                // setting can hold and refuses the whole change on any error.
                'rules' => [
                    'site_language' => ['sometimes', 'string', 'max:32'],
                    'timezone' => ['sometimes', 'string', 'max:64'],
                    'date_format' => ['sometimes', 'string', 'max:64'],
                    'time_format' => ['sometimes', 'string', 'max:64'],
                    'permalink_structure' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'search_engine_visibility' => ['sometimes', 'boolean'],
                    'wp_memory_limit' => ['sometimes', 'string', 'max:16'],
                    'wp_max_memory_limit' => ['sometimes', 'string', 'max:16'],
                ],
                'argv' => fn ($in) => ['settings', 'set', ...self::settingsFlags($in)],
                'async' => false, 'mutates' => true,
            ],
            'maintenance.get' => self::read(fn () => ['maintenance-mode', 'status']),
            'maintenance.set' => [
                'rules' => ['active' => ['required', 'boolean']],
                'argv' => fn ($in) => ['maintenance-mode', (bool) $in['active'] ? 'activate' : 'deactivate'],
                'async' => false, 'mutates' => true,
            ],

            // ── blueprint ────────────────────────────────────────────────────
            // The blueprint is saved in Central (it was v7's
            // wordpress_blueprints table) and sent whole; it goes to the
            // toolkit on stdin, script and all, never on a command line.
            'blueprint.apply' => [
                'rules' => [
                    'selected_plugins' => ['sometimes', 'array', 'max:100'],
                    'selected_plugins.*.slug' => ['required', 'string', 'max:200', 'regex:/^'.self::SLUG.'$/'],
                    'selected_plugins.*.name' => ['sometimes', 'nullable', 'string', 'max:200'],
                    'selected_plugins.*.activate' => ['sometimes', 'boolean'],
                    'selected_themes' => ['sometimes', 'array', 'max:50'],
                    'selected_themes.*.slug' => ['required', 'string', 'max:200', 'regex:/^'.self::SLUG.'$/'],
                    'selected_themes.*.name' => ['sometimes', 'nullable', 'string', 'max:200'],
                    'selected_themes.*.activate' => ['sometimes', 'boolean'],
                    'custom_theme_plugins' => ['sometimes', 'array', 'max:50'],
                    'custom_theme_plugins.*.label' => ['sometimes', 'nullable', 'string', 'max:200'],
                    'custom_theme_plugins.*.link' => ['required', 'string', 'max:2048', 'regex:/^https:\/\/\S+$/'],
                    'custom_theme_plugins.*.type' => ['required', 'in:custom-plugin,custom-theme'],
                    'custom_theme_plugins.*.activate' => ['sometimes', 'boolean'],
                    'remove_hello_world' => ['sometimes', 'boolean'],
                    'remove_sample_page' => ['sometimes', 'boolean'],
                    'delete_all_themes' => ['sometimes', 'boolean'],
                    'delete_all_plugins' => ['sometimes', 'boolean'],
                    'delete_unneeded_core_file' => ['sometimes', 'boolean'],
                    'language' => ['sometimes', 'nullable', 'string', 'max:32'],
                    'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
                    'date_format' => ['sometimes', 'nullable', 'string', 'max:64'],
                    'time_format' => ['sometimes', 'nullable', 'string', 'max:64'],
                    'se_indexing_disable' => ['sometimes', 'boolean'],
                    'organize_upload_folders' => ['sometimes', 'boolean'],
                    'permalink_structure' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'debug_mode' => ['sometimes', 'boolean'],
                    'debug_log' => ['sometimes', 'boolean'],
                    'debug_error' => ['sometimes', 'boolean'],
                    // Run after setup as the site's owner, never root.
                    'script' => ['sometimes', 'nullable', 'string', 'max:65536'],
                ],
                'argv' => fn () => ['blueprint', 'apply'],
                'input' => fn ($in) => json_encode(self::blueprint($in), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                // Downloads and installs; and the script may take minutes.
                'async' => true, 'mutates' => true,
            ],

            // ── web server rules (no WP-CLI) ─────────────────────────────────
            'security.get' => self::read(fn () => ['security', 'status']) + ['rules_target' => true],
            'security.set' => [
                'rules' => ['blocked' => ['required', 'boolean']],
                'argv' => fn ($in, $p) => ['security', $p['rule'], (bool) $in['blocked'] ? 'block' : 'allow'],
                'async' => false, 'mutates' => true, 'rules_target' => true,
            ],
        ];
    }

    /** @return array{rules: array<string, mixed>, argv: callable, async: bool, mutates: bool, rules_target?: bool} */
    public static function get(string $command): array
    {
        return self::all()[$command] ?? throw new InvalidArgumentException("Unknown wp-toolkit command [{$command}].");
    }

    /**
     * Only the settings that were sent become flags — an absent key leaves
     * the setting alone, and an empty permalink is a deliberate "plain links".
     *
     * @param  array<string, mixed>  $in
     * @return array<int, string>
     */
    public static function settingsFlags(array $in): array
    {
        $flags = [];

        foreach (['site_language', 'timezone', 'date_format', 'time_format', 'permalink_structure', 'search_engine_visibility', 'wp_memory_limit', 'wp_max_memory_limit'] as $key) {
            if (! array_key_exists($key, $in)) {
                continue;
            }

            $value = $in[$key];
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            // `--flag=value` as one element, so a value starting with a dash
            // can never be read as the next flag.
            $flags[] = '--'.str_replace('_', '-', $key).'='.$value;
        }

        return $flags;
    }

    /**
     * The blueprint as the toolkit reads it: only the fields it knows (it
     * refuses unknown ones), with nulls dropped.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function blueprint(array $in): array
    {
        $item = fn (array $i): array => array_filter([
            'name' => $i['name'] ?? null, 'slug' => $i['slug'] ?? null, 'activate' => (bool) ($i['activate'] ?? false),
        ], fn ($v) => $v !== null);
        $custom = fn (array $i): array => array_filter([
            'label' => $i['label'] ?? null, 'link' => $i['link'] ?? null, 'type' => $i['type'] ?? null, 'activate' => (bool) ($i['activate'] ?? false),
        ], fn ($v) => $v !== null);

        $out = [
            'selected_plugins' => array_map($item, $in['selected_plugins'] ?? []),
            'selected_themes' => array_map($item, $in['selected_themes'] ?? []),
            'custom_theme_plugins' => array_map($custom, $in['custom_theme_plugins'] ?? []),
        ];
        foreach (['remove_hello_world', 'remove_sample_page', 'delete_all_themes', 'delete_all_plugins', 'delete_unneeded_core_file', 'se_indexing_disable', 'organize_upload_folders', 'debug_mode', 'debug_log', 'debug_error'] as $flag) {
            $out[$flag] = (bool) ($in[$flag] ?? false);
        }
        foreach (['language', 'timezone', 'date_format', 'time_format', 'permalink_structure', 'script'] as $text) {
            if (filled($in[$text] ?? null)) {
                $out[$text] = (string) $in[$text];
            }
        }

        return $out;
    }

    private static function read(callable $argv): array
    {
        return ['rules' => [], 'argv' => $argv, 'async' => false, 'mutates' => false];
    }

    private static function write(callable $argv, bool $async = false): array
    {
        return ['rules' => [], 'argv' => $argv, 'async' => $async, 'mutates' => true];
    }
}
