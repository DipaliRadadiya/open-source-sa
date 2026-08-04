<?php

namespace App\Enums;

/**
 * Where a panel update is.
 *
 * `Running` is the state that needs care: the update restarts php-fpm and the
 * queue, so the process that set it may be gone before it can set anything
 * else. A row is only moved out of `Running` by reading the runner's state
 * file — never by a PHP process assuming it survived.
 */
enum PanelUpdateStatus: string
{
    /** Row written, runner not yet observed to have started. */
    case Pending = 'pending';

    /** The detached runner is working. */
    case Running = 'running';

    /** New version is live and answered a health check. */
    case Succeeded = 'succeeded';

    /** Failed. `reason` says how; `rolled_back` says whether the old release is back. */
    case Failed = 'failed';

    /** Still in flight — a row in one of these must not be re-opened or re-run. */
    public function inFlight(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }

    public function label(): string
    {
        return __('panel_update.status.'.$this->value);
    }
}
