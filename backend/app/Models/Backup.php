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
        });
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'backup_target_id');
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
