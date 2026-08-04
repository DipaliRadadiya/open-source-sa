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
    'file_excludes', 'database_excludes', 'enabled', 'frequency', 'last_run_at',
])]
class BackupTarget extends Model
{
    /** @var list<string> */
    public const FREQUENCIES = ['manual', 'daily', 'weekly', 'monthly'];

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

    public function cronExpression(): ?string
    {
        return self::CRON[$this->frequency] ?? null;
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
