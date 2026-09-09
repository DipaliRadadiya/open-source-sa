<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['backup_target_id', 'application_id', 'user_id', 'type', 'is_safety', 'status', 'manifest', 'reason', 'reference', 'log_key', 'size_bytes', 'started_at', 'finished_at', 'verified_at'])]
class Backup extends Model
{
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'is_safety' => 'boolean',
            'status' => BackupStatus::class,
            'manifest' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The archive's name on the storage destination.
     *
     * Set here rather than by the caller, for the reason {@see Worker::booted()}
     * gives: leaving it to callers means every creation site has to remember,
     * and the one that forgets fails at the database instead of anywhere
     * useful. There is only one creation site today; a uid that exists because
     * of where it is assigned rather than because someone remembered is the
     * point.
     *
     * Not fillable, deliberately. This is what stops one backup's archive from
     * overwriting another's, so nothing outside this model gets to choose it —
     * an id would do, right up until the panel is reinstalled and the counter
     * starts again over a bucket that still holds the old archives.
     */
    protected static function booted(): void
    {
        static::creating(function (self $backup): void {
            if (($backup->uid ?? '') === '') {
                $backup->uid = (string) Str::uuid();
            }

            // Stamped here for the same reason as the uid above, and not left
            // fillable: a target's destination is editable, so a backup that
            // reads it back later reads *today's* answer about where a months
            // old archive was written. There is one creation site today; this
            // is what stops the second one from having to remember.
            if ($backup->storage_destination_id === null && $backup->backup_target_id !== null) {
                $backup->storage_destination_id = BackupTarget::query()
                    ->whereKey($backup->backup_target_id)
                    ->value('storage_destination_id');
            }
        });
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'backup_target_id');
    }

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    /**
     * Where this archive actually lives.
     *
     * Recorded on the row when the upload happens, because a target's
     * destination is editable and reaching it through the target moved
     * *history*: repoint a target and every older backup started resolving to
     * a bucket its archive was never in — download said "missing", delete
     * removed the row and orphaned the object, and the list named the wrong
     * provider.
     *
     * The fallback covers a row written before that column existed and never
     * backfilled (its target or destination had already gone). It is the old
     * behaviour, kept only where there is nothing better to answer with.
     */
    public function destination(): ?StorageDestination
    {
        return $this->storageDestination ?? $this->target?->storageDestination;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
