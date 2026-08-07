<?php

namespace App\Models;

use App\Enums\CloneStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source_application_id', 'target_application_id', 'user_id',
    'name', 'domain', 'status', 'current_step', 'reason', 'reference',
    'started_at', 'finished_at',
])]
class SiteClone extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CloneStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function sourceApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'source_application_id');
    }

    public function targetApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'target_application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
