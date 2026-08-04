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
