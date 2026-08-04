<?php

return [
    'checks' => [
        'privilege' => 'Privileged commands',
        'services' => 'Services',
        'writable_paths' => 'Writable paths',
        'database' => 'Database',
        'health_endpoint' => 'Health endpoint',
    ],
    'fixes' => [
        'privilege' => 'The panel cannot run commands as root. Check /etc/sudoers.d/ contains the panel grant and that the file passes visudo -c.',
        'privilege_disabled' => 'Privilege escalation is switched off but the panel is not root. Remove SERVER_OPS_SUDO=false from .env.',
        'services_missing' => 'A unit the panel expects does not exist. Set PANEL_FRONTEND_SERVICE and PANEL_QUEUE_SERVICE in .env to the names this server actually uses.',
        'services_down' => 'Start them with systemctl start, then check journalctl -u <unit> for why they stopped.',
        'writable_paths' => 'Give the panel account ownership: chown -R <panel user> on the paths listed above.',
        'database_unreachable' => 'Check the DB_ settings in .env and that the database service is running.',
        'database_pending' => 'Run php artisan migrate --force. Code was updated without applying its schema changes.',
        'health_unreachable' => 'Check APP_URL in .env matches the address this panel is served on, and that the web server and php-fpm are running.',
        'health_version_mismatch' => 'The running code and the served version differ. Clear the caches with php artisan optimize:clear and reload php-fpm.',
    ],
];
