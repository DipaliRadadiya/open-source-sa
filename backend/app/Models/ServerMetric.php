<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One sampled server-metrics data point (5-min cadence, 24h retention).
 */
#[Fillable([
    'cpu_percent', 'memory_percent', 'swap_percent', 'disk_percent',
    'load_1', 'load_5', 'load_15', 'net_in', 'net_out', 'sampled_at',
])]
class ServerMetric extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'memory_percent' => 'float',
            'swap_percent' => 'float',
            'disk_percent' => 'float',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
            'net_in' => 'integer',
            'net_out' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }
}
