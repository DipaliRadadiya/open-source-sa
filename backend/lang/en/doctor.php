<?php

return [
    'checks' => [
        'privilege' => 'Privileged commands',
        'services' => 'Services',
        'writable_paths' => 'Writable paths',
        'database' => 'Database',
        'health_endpoint' => 'Health endpoint',
        'binaries' => 'Required tools',
        'web_server' => 'Web server',
        'queue' => 'Queue worker',
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
        'binaries_required' => 'Install the missing packages. Without them core features cannot run at all.',
        'binaries_optional' => 'Each missing tool disables the feature named beside it. Install it from the setup page, or ignore it if you do not need that feature.',
        'web_server_missing' => 'No supported web server was found. Install nginx or Apache.',
        'web_server_undrivable' => 'The panel cannot write configuration for this web server, so sites cannot be created. Switch to nginx or Apache.',
        'web_server_config' => 'The web server configuration is invalid. Run its own config test to see why — the next reload will fail until it is fixed.',
        'queue_stalled' => 'Jobs are queued but nothing is processing them. Restart the queue service; provisioning, deploys and installs will not finish until it runs.',
        'queue_failed_jobs' => 'Some background jobs failed. Check the failed_jobs table — work that was silently discarded is often why a feature appeared to do nothing.',
        'queue_unreadable' => 'The queue tables could not be read. Run php artisan migrate --force.',
    ],
];
