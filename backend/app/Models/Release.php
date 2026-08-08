<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property string $path
 * @property string|null $commit_hash
 * @property string|null $committed_at
 * @property Carbon|null $deployed_at
 * @property string $status
 * @property string|null $output
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Application $application
 */
class Release extends Model
{
    protected $fillable = [
        'application_id',
        'path',
        'commit_hash',
        'committed_at',
        'deployed_at',
        'status',
        'output',
    ];

    protected $casts = [
        'deployed_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
