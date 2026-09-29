<?php

namespace App\Models;

use App\Enums\BackupType;
use App\Enums\RestoreStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'backup_id', 'application_id', 'user_id', 'type', 'status', 'current_step',
    'reason', 'reference', 'safety_backup_id', 'rollback_path',
    'started_at', 'finished_at',
])]
class Restore extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'status' => RestoreStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Whether this application has a restore queued or running.
     *
     * A backup taken while a restore is extracting, loading the database or
     * swapping files captures a half-restored site, is marked verified, and
     * then lets retention prune an older good backup to make room for it
     * (found in code review 2026-09-29). Every way a backup is started asks
     * this first; the restore's own safety backup runs the runner directly
     * and is not affected.
     */
    public static function inProgressFor(int $applicationId): bool
    {
        return static::query()
            ->where('application_id', $applicationId)
            ->whereIn('status', [RestoreStatus::Pending->value, RestoreStatus::Running->value])
            ->exists();
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'safety_backup_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
