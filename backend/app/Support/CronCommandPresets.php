<?php

namespace App\Support;

/**
 * Framework command presets for the cron "command" field: one click fills the command and a
 * recommended schedule. `{path}` and `{php}` are placeholders the frontend swaps for the
 * selected application's directory and PHP binary. `custom` has null command/expression, so
 * the UI shows raw fields.
 *
 * Interim source: these should move onto each SiteType.
 */
class CronCommandPresets
{
    /**
     * The token the frontend replaces with the application's absolute directory.
     */
    public const PLACEHOLDER = '{path}';

    /**
     * The token the frontend replaces with the chosen application's own PHP binary
     * (`php_binary`), so a site on 8.3 gets a job on 8.3. With no application chosen it
     * becomes plain `php`, the server default.
     */
    public const PHP_PLACEHOLDER = '{php}';

    /**
     * @var array<string, array{command: string|null, expression: string|null}>
     */
    public const PRESETS = [
        'laravel' => ['command' => '{php} {path}/artisan schedule:run', 'expression' => '* * * * *'],
        'wordpress' => ['command' => '{php} {path}/wp-cron.php', 'expression' => '*/5 * * * *'],
        'moodle' => ['command' => '{php} {path}/admin/cli/cron.php', 'expression' => '* * * * *'],
        'joomla' => ['command' => '{php} {path}/cli/joomla.php scheduler:run', 'expression' => '* * * * *'],
        'nextcloud' => ['command' => '{php} -f {path}/cron.php', 'expression' => '*/5 * * * *'],
        'craftcms' => ['command' => '{php} {path}/craft queue/run', 'expression' => '* * * * *'],
        'php_script' => ['command' => '{php} {path}/script.php', 'expression' => '0 * * * *'],
        'custom' => ['command' => null, 'expression' => null],
    ];

    /**
     * Presets with localized labels, in display order.
     *
     * @return array<int, array{key: string, label: string, command: string|null, expression: string|null}>
     */
    public static function all(): array
    {
        $presets = [];

        foreach (self::PRESETS as $key => $preset) {
            $presets[] = [
                'key' => $key,
                'label' => __('cronjob.commands.'.$key),
                'command' => $preset['command'],
                'expression' => $preset['expression'],
            ];
        }

        return $presets;
    }
}
