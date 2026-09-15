<?php

namespace App\Enums;

use App\Services\Server\Applications\LegacyPm2Driver;

/**
 * What is keeping an application's process alive.
 *
 * Two answers, and the second one only exists because of migration. A server
 * adopted from the old panel already has its applications running under a
 * per-user PM2 daemon, and taking them over cannot mean restarting them — so
 * the panel has to be able to manage an application whose unit it does not
 * own, for as long as the customer leaves it there.
 *
 * `Pm2` is therefore a supported destination, not a waiting room. An adopted
 * application works indefinitely without anyone converting anything; what it
 * gives up is listed on {@see LegacyPm2Driver}.
 */
enum SupervisorMode: string
{
    /**
     * The panel's own systemd unit.
     *
     * `ExecStart` is `node` directly, or `pm2-runtime` when the application
     * asked for more than one process. Either way systemd owns the boot hook,
     * the cgroup and the memory ceiling. The default for everything the panel
     * creates itself.
     */
    case Systemd = 'systemd';

    /**
     * The old panel's per-user PM2 daemon, adopted in place.
     *
     * There is no unit. The panel drives `pm2` as the site user and the dump
     * file is what brings the application back at boot — which means every
     * state change has to be saved, deliberately, rather than sometimes.
     */
    case Pm2 = 'pm2';

    public function label(): string
    {
        return __('application.supervisor_mode.'.$this->value);
    }
}
