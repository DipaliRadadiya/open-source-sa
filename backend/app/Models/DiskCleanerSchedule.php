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
#[Fillable(['enabled', 'frequency', 'categories', 'threshold_percent', 'keep_days', 'last_run_at'])]
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
            'keep_days' => 'integer',
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
            'keep_days' => null,
        ]);
    }

    public function cronExpression(): string
    {
        return self::CRON[$this->frequency] ?? self::CRON['weekly'];
    }

    /**
     * The timezone this schedule is interpreted in.
     *
     * The app's, because that is what the scheduler tick compares against; not
     * `ServerTimezone::get()`. See BackupTarget::scheduleTimezone().
     */
    public function scheduleTimezone(): string
    {
        return (string) config('app.timezone');
    }

    /**
     * When the cleaner will next run, or null when it is switched off.
     *
     * Published so the UI can show a concrete time instead of just "weekly" (03:00 is hidden).
     */
    public function nextRunAt(?DateTimeInterface $now = null): ?DateTimeInterface
    {
        if (! $this->enabled) {
            return null;
        }

        return (new CronExpression($this->cronExpression()))->getNextRunDate($now ?? now());
    }

    /**
     * Whether a scheduled run is due: a cron slot has passed since the last run
     * (rotation-safe against missed scheduler ticks).
     */
    public function isDue(DateTimeInterface $now): bool
    {
        $previousSlot = (new CronExpression($this->cronExpression()))
            ->getPreviousRunDate($now, 0, true);

        // Never run yet: count from the last save, so the first run lands on the first slot after
        // it (the time nextRunAt() reports), not right away.
        $since = $this->last_run_at ?? $this->updated_at;

        return $since === null || $since < $previousSlot;
    }
}
