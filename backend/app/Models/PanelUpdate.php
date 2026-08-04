<?php

namespace App\Models;

use App\Enums\PanelUpdateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'status', 'current_step',
    'from_version', 'from_commit', 'to_version', 'to_commit',
    'reason', 'reference', 'rolled_back', 'started_at', 'finished_at',
])]
class PanelUpdate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PanelUpdateStatus::class,
            'rolled_back' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
