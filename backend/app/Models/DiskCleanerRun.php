<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single disk-cleaner execution (manual or scheduled) — the run history.
 */
#[Fillable(['trigger', 'categories', 'freed', 'freed_total', 'status', 'disk_percent'])]
class DiskCleanerRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'freed' => 'array',
            'freed_total' => 'integer',
            'disk_percent' => 'integer',
        ];
    }
}
