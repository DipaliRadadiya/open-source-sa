<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id', 'name', 'command', 'kind', 'directory',
    'processes', 'stop_wait_seconds', 'auto_restart', 'restart_on_deploy', 'enabled',
])]
class Worker extends Model
{
    /** Laravel's own queue worker — restarted gracefully with `queue:restart`. */
    public const KIND_QUEUE = 'queue';

    /** Horizon supervises its own workers — `horizon:terminate`. */
    public const KIND_HORIZON = 'horizon';

    /** Anything else: the unit is restarted directly. */
    public const KIND_CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processes' => 'integer',
            'stop_wait_seconds' => 'integer',
            'auto_restart' => 'boolean',
            'restart_on_deploy' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Horizon and a plain queue worker on the same application both consume
     * the same queue, so every job is picked up twice. Neither tool can detect
     * the other, which is why the panel has to.
     */
    public function conflictsWith(Worker $other): bool
    {
        $queueing = [self::KIND_QUEUE, self::KIND_HORIZON];

        return $this->kind !== $other->kind
            && in_array($this->kind, $queueing, true)
            && in_array($other->kind, $queueing, true);
    }
}
