<?php

namespace App\Models;

use App\Enums\BackupType;
use App\Enums\RestoreStatus;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'backup_id', 'application_id', 'database_id', 'user_id', 'type', 'status', 'current_step',
    'reason', 'reference', 'safety_backup_id', 'safety_copy', 'rollback_path',
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
            'safety_copy' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Create a restore row only if nothing for the same site or database is
     * already queued or running — checked and created under one lock.
     *
     * The request's own check ran before this and was not enough: two
     * requests arriving together both passed it, both created a pending row,
     * and the queue dropped the second job (RunRestore is unique per site or
     * database). That row stayed `pending` for ever, and everything that asks
     * "is a restore in flight" — restore, delete, scheduled backups — refused
     * for ever too (QA, 2026-11-24).
     *
     * @param  string  $scope  `application-{id}` or `database-{id}`, as RunRestore::uniqueId()
     * @param  Closure(): bool  $busy
     * @param  Closure(): self  $create
     */
    public static function startExclusively(string $scope, Closure $busy, string $busyMessage, Closure $create): self
    {
        $refuse = fn () => ValidationException::withMessages(['backup' => [__($busyMessage)]]);

        try {
            return Cache::lock('restore-start:'.$scope, 30)->block(5, function () use ($busy, $refuse, $create): self {
                if ($busy()) {
                    throw $refuse();
                }

                return $create();
            });
        } catch (LockTimeoutException) {
            // Another request for the same site or database is starting one
            // right now: the same answer as one already running.
            throw $refuse();
        }
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

    /**
     * Whether this database (on its own, not through a site) has a restore
     * queued or running. The same guard as {@see self::inProgressFor()}, for
     * the same reason: a backup or an import taken mid-restore captures, or
     * replaces, a half-restored database.
     */
    public static function inProgressForDatabase(int $databaseId): bool
    {
        return static::query()
            ->where('database_id', $databaseId)
            ->whereIn('status', [RestoreStatus::Pending->value, RestoreStatus::Running->value])
            ->exists();
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
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
