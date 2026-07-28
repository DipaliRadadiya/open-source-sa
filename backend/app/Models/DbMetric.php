<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['engine', 'queries', 'connections', 'threads_running', 'sampled_at'])]
class DbMetric extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queries' => 'integer',
            'connections' => 'integer',
            'threads_running' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }
}
