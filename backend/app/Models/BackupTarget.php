<?php

namespace App\Models;

use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'storage_destination_id', 'type', 'retention_count', 'file_excludes', 'database_excludes', 'enabled'])]
class BackupTarget extends Model
{
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'retention_count' => 'integer',
            'file_excludes' => 'array',
            'database_excludes' => 'array',
            'enabled' => 'boolean',
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
}
