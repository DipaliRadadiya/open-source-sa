<?php

namespace App\Models;

use App\Enums\SyncAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'sync_run_id', 'resource_type', 'resource_key', 'action',
    'model_type', 'model_id', 'confidence', 'evidence', 'reason',
])]
class SyncItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => SyncAction::class,
            'evidence' => 'array',
            'confidence' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
