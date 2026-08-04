<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key'])]
class StorageDestination extends Model
{
    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
        ];
    }

    public function backupTargets(): HasMany
    {
        return $this->hasMany(BackupTarget::class);
    }
}
