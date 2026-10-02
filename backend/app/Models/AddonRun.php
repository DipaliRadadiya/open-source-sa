<?php

namespace App\Models;

use App\Jobs\RunAddonCommand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued addon command and, once it finished, what the addon answered.
 * {@see RunAddonCommand}
 */
class AddonRun extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $fillable = ['application_id', 'addon', 'command', 'status', 'arguments', 'http_status', 'result', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'http_status' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
