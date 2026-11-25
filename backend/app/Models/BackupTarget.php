<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What to back up for one application, where to, how often, and how many to keep.
 *
 * The schedule lives in this row, not a cron file, so it cannot drift with the user-managed
 * Cronjobs feature. Same frequency vocabulary as DiskCleanerSchedule.
 */
#[Fillable([
    'application_id', 'database_id', 'storage_destination_id', 'type', 'retention_count',
    'file_excludes', 'database_excludes', 'volume_scope', 'enabled', 'frequency', 'schedule_time', 'last_run_at',
])]
class BackupTarget extends Model
{
    /**
     * In dropdown order. The options endpoint and the save validation both read this.
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
     * Friendly frequency → cron expression. 02:00, not the disk cleaner's 03:00, so the two do
     * not compete for disk and CPU on a small VPS.
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
            // Null means every volume, including ones added later. An empty array is read the
            // same way, not as "none": a target that captures nothing but succeeds is the worst state.
            'volume_scope' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * A target belongs to a site or to a database, never both. See {@see self::subjectName()}.
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function isDatabaseTarget(): bool
    {
        return $this->database_id !== null;
    }

    /** What this target backs up, by name: the site's domain or the database's name. */
    public function subjectName(): string
    {
        return $this->isDatabaseTarget()
            ? (string) $this->database?->name
            : (string) ($this->application?->name ?? '');
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
     * The newest run of this target, whatever its status, never a pre-restore safety copy. Same
     * rule as the coverage list's `state` (BackupCoverage), so `last_backup` and `state` agree.
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(Backup::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('is_safety', false),
        );
    }

    /**
     * The newest good backup that still exists: what "last backup" means to a user.
     *
     * Not `last_run_at`, which must survive deleting backups or `isDue()` would read null as
     * "never ran" and take a new one on the next tick.
     */
    public function latestKeptBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', BackupStatus::Verified),
        );
    }

    /**
     * The timezone this schedule is interpreted in.
     *
     * The app's timezone, because `RunScheduledBackups` and `isDue()` both resolve the cron slot
     * in it.
     *
     * Never `ServerTimezone::get()`: Cronjob uses that because Linux cron runs on the OS clock,
     * but backups do not, and it would label the schedule with a confident, wrong time.
     *
     * A method so the resource's label follows if this ever moves to server time.
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

            // The chosen time is one of the runs. The range must start at the hour modulo N:
            // `14-23/12` means "from 14:00" and would run once a day, `2-23/12` runs 02:30 and 14:30.
            return (int) $minute.' '.((int) $hour % $every).'-23/'.$every.' * * *';
        }

        $base = self::CRON[$this->frequency] ?? null;

        if ($base === null || $this->schedule_time === null) {
            return $base;
        }

        // Replace only minute (field 0) and hour (field 1); weekly keeps its day-of-week and
        // monthly its day-of-month.
        $parts = preg_split('/\s+/', $base);
        [$hour, $minute] = explode(':', $this->schedule_time, 2);

        $parts[0] = $minute;
        $parts[1] = (string) $hour;

        return implode(' ', $parts);
    }

    /**
     * When the next scheduled run will happen.
     *
     * Computed here, not published as a cron string, so the schedule stays defined in one place.
     *
     * Null when nothing is scheduled (disabled or manual).
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
     * Compares the last run against the most recent cron slot, not "now minus an interval", so a
     * missed scheduler tick (reboot, worker restart) still fires once afterwards.
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
