<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'resource_type', 'resource_key', 'note'])]
class SyncIgnore extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The ignore list as `type:key` strings, for one cheap lookup per run
     * rather than a query per discovered item.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return static::query()
            ->get(['resource_type', 'resource_key'])
            ->map(fn (self $ignore): string => $ignore->resource_type.':'.$ignore->resource_key)
            ->all();
    }
}
