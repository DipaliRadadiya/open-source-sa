<?php

return [
    /*
     * The panel updater may only perform these fixed lifecycle steps. Values
     * are server-owned configuration, never request input. The privileged
     * helper will consume this manifest; controllers/jobs cannot name commands.
     */
    'release_root' => env('PANEL_RELEASE_ROOT', '/var/www/panel/releases'),
    'current_link' => env('PANEL_CURRENT_LINK', '/var/www/panel/current'),
    'shared_root' => env('PANEL_SHARED_ROOT', '/var/www/panel/shared'),
    'repository' => env('PANEL_REPOSITORY', 'https://github.com/DipaliRadadiya/open-source-sa.git'),
    'release_branch' => env('PANEL_RELEASE_BRANCH', 'main'),
    'services' => [
        'php_fpm' => env('PANEL_PHP_FPM_SERVICE', 'php8.4-fpm'),
        'frontend' => env('PANEL_FRONTEND_SERVICE', 'panel-frontend'),
        'queue' => env('PANEL_QUEUE_SERVICE', 'panel-queue'),
    ],
    'steps' => [
        'fetch_release',
        'composer_install',
        'frontend_build',
        'backup_panel_database',
        'enable_maintenance',
        'switch_release',
        'migrate',
        'seed_permissions',
        'cache',
        'reload_services',
        'health_check',
        'disable_maintenance',
    ],
];
