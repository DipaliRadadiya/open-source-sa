<?php

namespace App\Support;

/**
 * Canonical cron schedule presets fed to the frontend dropdown. `custom` has a
 * null expression — the UI shows a raw expression field for it. Labels are
 * resolved from lang/<locale>/cronjob.php at read time.
 */
class CronSchedulePresets
{
    /**
     * @var array<string, string|null>
     */
    public const PRESETS = [
        'every_minute' => '* * * * *',
        'every_5_minutes' => '*/5 * * * *',
        'every_15_minutes' => '*/15 * * * *',
        'every_30_minutes' => '*/30 * * * *',
        'hourly' => '0 * * * *',
        'twice_daily' => '0 0,12 * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
        'custom' => null,
    ];

    /**
     * Presets with localized labels, in display order.
     *
     * @return array<int, array{key: string, label: string, expression: string|null}>
     */
    public static function all(): array
    {
        $presets = [];

        foreach (self::PRESETS as $key => $expression) {
            $presets[] = [
                'key' => $key,
                'label' => __('cronjob.presets.'.$key),
                'expression' => $expression,
            ];
        }

        return $presets;
    }
}
