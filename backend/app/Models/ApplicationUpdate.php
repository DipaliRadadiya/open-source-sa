<?php

namespace App\Models;

use App\Enums\ApplicationUpdateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'user_id', 'from_version', 'to_version', 'status', 'reason', 'step', 'reference', 'started_at', 'finished_at'])]
class ApplicationUpdate extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ApplicationUpdateStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
