<?php

namespace App\Models;

use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The (singleton) automatic disk-cleaner profile. This DB row is the single
 * source of truth for the schedule — the Laravel scheduler reads it each tick
 * and decides whether to run. There is intentionally NO cron file, so it can
 * never drift with the user-managed Cronjobs feature.
 */
#[Fillable(['enabled', 'frequency', 'categories', 'threshold_percent', 'notify', 'last_run_at'])]
class DiskCleanerSchedule extends Model
{
    /** @var array<int, string> */
    public const FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly'];

    /** Friendly frequency → cron expression (daily/weekly/monthly run at 03:00). */
    private const CRON = [
        'hourly' => '0 * * * *',
        'daily' => '0 3 * * *',
        'weekly' => '0 3 * * 0',
        'monthly' => '0 3 1 * *',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'categories' => 'array',
            'threshold_percent' => 'integer',
            'notify' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * The current profile, or an unsaved default when none exists yet.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new static([
            'enabled' => false,
            'frequency' => 'weekly',
            'categories' => [],
            'threshold_percent' => null,
            'notify' => false,
        ]);
    }

    public function cronExpression(): string
    {
        return self::CRON[$this->frequency] ?? self::CRON['weekly'];
    }

    /**
     * Whether a scheduled run is due: a cron slot has passed since the last run
     * (rotation-safe against missed scheduler ticks).
     */
    public function isDue(DateTimeInterface $now): bool
    {
        $previousSlot = (new CronExpression($this->cronExpression()))
            ->getPreviousRunDate($now, 0, true);

        return $this->last_run_at === null || $this->last_run_at < $previousSlot;
    }
}
