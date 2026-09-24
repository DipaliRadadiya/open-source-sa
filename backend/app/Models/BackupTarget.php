<?php

namespace App\Models;

use App\Enums\BackupType;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What to back up for one application, where to, how often, and how many to
 * keep.
 *
 * The schedule lives in this row rather than in a cron file, so it can never
 * drift with the user-managed Cronjobs feature — same reasoning and same
 * frequency vocabulary as DiskCleanerSchedule.
 */
#[Fillable([
    'application_id', 'storage_destination_id', 'type', 'retention_count',
    'file_excludes', 'database_excludes', 'enabled', 'frequency', 'schedule_time', 'last_run_at',
])]
class BackupTarget extends Model
{
    /**
     * In the order a dropdown shows them. The options endpoint and the save
     * validation both read this, so what is offered and what is accepted are
     * one list.
     *
     * @var list<string>
     */
    public const FREQUENCIES = [
        'manual', 'hourly', 'every_3_hours', 'every_6_hours', 'every_12_hours',
        'daily', 'weekly', 'monthly',
    ];

    /** How many backups a target may keep. */
    public const RETENTION_MIN = 1;

    public const RETENTION_MAX = 365;

    /**
     * Frequencies that repeat within a day, and every how many hours.
     *
     * @var array<string, int>
     */
    private const EVERY_HOURS = [
        'every_3_hours' => 3,
        'every_6_hours' => 6,
        'every_12_hours' => 12,
    ];

    /**
     * Friendly frequency → cron expression. 02:00 rather than the disk
     * cleaner's 03:00 so the two do not contend for the same disk and CPU on
     * a small VPS — a backup competing with a cleanup is how both get slow.
     */
    private const CRON = [
        'daily' => '0 2 * * *',
        'weekly' => '0 2 * * 0',
        'monthly' => '0 2 1 * *',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'retention_count' => 'integer',
            'file_excludes' => 'array',
            'database_excludes' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * The timezone this schedule is interpreted in.
     *
     * The app's, because that is what `RunScheduledBackups` compares against:
     * it calls `Date::now()`, and `isDue()` resolves the cron slot in the same
     * zone. So "02:00" on this target means 02:00 here, and on a server set to
     * anything else it does NOT mean 02:00 to the person who typed it.
     *
     * 🔴 **Not `ServerTimezone::get()`, and the difference is the whole point.**
     * Cronjob uses that because Linux cron genuinely runs on the OS clock.
     * Backups do not. Naming the server's timezone on a schedule evaluated in
     * UTC would print a confident, specific, wrong time — worse than the bare
     * number it replaces, since a user cannot tell it is wrong.
     *
     * A method rather than the config read inline in the resource: the day
     * these move to server time, this is the one line that changes and the
     * label follows. A resource holding its own copy is a label that goes
     * stale the moment the clock moves — which is the bug this exists to fix.
     */
    public function scheduleTimezone(): string
    {
        return (string) config('app.timezone');
    }

    /**
     * Which part of `schedule_time` a frequency uses: `minute` (hourly),
     * `time` (the rest), or null when nothing is scheduled (manual).
     */
    public static function timeUsage(string $frequency): ?string
    {
        return match (true) {
            $frequency === 'manual' => null,
            $frequency === 'hourly' => 'minute',
            default => 'time',
        };
    }

    public function cronExpression(): ?string
    {
        if ($this->frequency === 'hourly') {
            [, $minute] = explode(':', $this->schedule_time ?? '02:00', 2);

            return (int) $minute.' * * * *';
        }

        if (isset(self::EVERY_HOURS[$this->frequency])) {
            $every = self::EVERY_HOURS[$this->frequency];
            [$hour, $minute] = explode(':', $this->schedule_time ?? '02:00', 2);

            // The chosen time is one of the runs, and the rest fall every N
            // hours around it. The range has to start at the hour modulo N:
            // `14-23/12` means "from 14:00", so 14:30 every 12 hours would run
            // once a day. `2-23/12` runs at 02:30 and 14:30, which is what was
            // asked for. Measured against the cron library, 2026-09-24.
            return (int) $minute.' '.((int) $hour % $every).'-23/'.$every.' * * *';
        }

        $base = self::CRON[$this->frequency] ?? null;

        if ($base === null || $this->schedule_time === null) {
            return $base;
        }

        // Replace only the minute (field 0) and hour (field 1) fields,
        // preserving the day-of-week (field 4) for weekly and day-of-month
        // (field 2) for monthly. "14:30" on a weekly cron keeps its Sunday.
        $parts = preg_split('/\s+/', $base);
        [$hour, $minute] = explode(':', $this->schedule_time, 2);

        $parts[0] = $minute;
        $parts[1] = (string) $hour;

        return implode(' ', $parts);
    }

    /**
     * When the next scheduled run will happen.
     *
     * Computed here rather than published as a cron string, so the schedule
     * stays one constant in one place — a frontend holding its own copy of
     * `0 2 * * *` is a copy that goes stale the day this changes.
     *
     * Null when nothing is scheduled: a disabled or manual target has no next
     * run, and showing one would promise a backup that is never taken.
     */
    public function nextRunAt(?DateTimeInterface $now = null): ?DateTimeInterface
    {
        if (! $this->enabled || $this->frequency === 'manual') {
            return null;
        }

        $expression = $this->cronExpression();

        if ($expression === null) {
            return null;
        }

        return (new CronExpression($expression))->getNextRunDate($now ?? now());
    }

    /**
     * Whether a scheduled run is due.
     *
     * Compares the last run against the most recent cron slot rather than
     * against "now minus an interval", so a scheduler tick that was missed —
     * a reboot, a busy box, a worker restart — still fires once when it comes
     * back rather than silently skipping the day.
     */
    public function isDue(DateTimeInterface $now): bool
    {
        if (! $this->enabled || $this->frequency === 'manual') {
            return false;
        }

        $expression = $this->cronExpression();

        if ($expression === null) {
            return false;
        }

        $previousSlot = (new CronExpression($expression))->getPreviousRunDate($now, 0, true);

        return $this->last_run_at === null || $this->last_run_at < $previousSlot;
    }
}
