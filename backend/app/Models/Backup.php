<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['backup_target_id', 'application_id', 'user_id', 'type', 'status', 'manifest', 'reason', 'reference', 'size_bytes', 'started_at', 'finished_at', 'verified_at'])]
class Backup extends Model
{
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'status' => BackupStatus::class,
            'manifest' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
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
