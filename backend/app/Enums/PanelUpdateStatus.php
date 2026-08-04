<?php

namespace App\Enums;

/**
 * Where a panel update got to.
 *
 * The lifecycle mirrors `DeploymentStatus` so the screen can treat both the
 * same way: a row that is `pending` or `running` is one to poll on, the rest
 * are terminal. There is one extra value here — `rolled_back` — because a panel
 * update is the only place this codebase ships a release it can revert.
 *
 * The rollback state is reserved for the privileged helper that does the
 * actual switch (not yet implemented). For now every update that runs ends
 * in `failed`, with `reason=unsupported`, because the no-op job is the only
 * worker there is.
 */
enum PanelUpdateStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return __('panel_update.status.'.$this->value);
    }

    /** Whether this row is still going, which is what the UI polls on. */
    public function inFlight(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
