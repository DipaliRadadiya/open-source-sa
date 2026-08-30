<?php

return [
    'not_installed' => 'fail2ban is not installed on this server.',
    'already_installed' => 'fail2ban is already installed.',
    'not_running' => 'fail2ban is installed but not running.',
    'foreign_jail_local' => 'The panel did not write :path, so it will not overwrite it. Fail2ban is already configured there by hand or by another panel — move or remove that file if you want the panel to manage these settings.',
    'jail_not_active' => 'The :jail jail is not active.',
    'not_banned' => 'That IP address is not currently banned.',
    'lockout_risk' => 'Enabling the SSH jail can lock you out of this server. Add your IP address to the ignore list, or confirm that you accept the risk.',
    'ip_ignored' => 'That IP address is on the ignore list, so a ban would not hold.',
    'operation_failed' => 'The fail2ban operation failed.',
    'bantime_too_short' => 'Ban time must be at least 60 seconds, or -1 for a permanent ban.',
];
