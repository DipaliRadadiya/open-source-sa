<?php

namespace App\Enums;

use App\Services\Server\Applications\LegacyPm2Driver;

/**
 * What is keeping an application's process alive.
 *
 * `Pm2` exists for migration: a server adopted from the old panel runs its applications under a
 * per-user PM2 daemon, and taking them over cannot mean restarting them.
 *
 * It is a supported destination, not a waiting room: an adopted application works indefinitely
 * without conversion; what it gives up is listed on {@see LegacyPm2Driver}.
 */
enum SupervisorMode: string
{
    /**
     * The panel's own systemd unit.
     *
     * `ExecStart` is `node` directly, or `pm2-runtime` for more than one process; systemd owns
     * the boot hook, the cgroup and the memory ceiling. The default for new applications.
     */
    case Systemd = 'systemd';

    /**
     * The old panel's per-user PM2 daemon, adopted in place.
     *
     * There is no unit. The panel drives `pm2` as the site user and the dump file brings the
     * application back at boot, so every state change has to be saved.
     */
    case Pm2 = 'pm2';

    public function label(): string
    {
        return __('application.supervisor_mode.'.$this->value);
    }
}
