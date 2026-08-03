<?php

namespace App\Enums;

/**
 * Where one deploy got to.
 *
 * `queued` is stored, unlike the runtime installer's `ready`: a deploy row is
 * written before the job is dispatched, so the screen can show "queued" for the
 * seconds before a worker picks it up. Without it a user who clicks Deploy sees
 * nothing at all and clicks again.
 */
enum DeploymentStatus: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    public function label(): string
    {
        return __('deployment.status.'.$this->value);
    }

    /** Whether this deploy is still going, which is what the UI polls on. */
    public function inFlight(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
