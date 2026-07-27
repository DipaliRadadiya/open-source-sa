<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System user home base
    |--------------------------------------------------------------------------
    |
    | Parent directory under which system users' home directories are created
    | (e.g. /home/<username>). Overridable so tests can point it at a writable
    | temp path.
    |
    */

    'home_base' => env('SERVER_HOME_BASE', '/home'),

    /*
    |--------------------------------------------------------------------------
    | Cron.d directory
    |--------------------------------------------------------------------------
    |
    | Directory where managed cron job files are written (one file per job).
    | Cron reads this automatically. Overridable so tests can point it at a
    | writable temp path.
    |
    */

    'cron_d' => env('SERVER_CRON_D', '/etc/cron.d'),

    /*
    |--------------------------------------------------------------------------
    | SSH port
    |--------------------------------------------------------------------------
    |
    | The port the SSH daemon listens on. Always kept open by the firewall
    | (lockout guard) and seeded as a default rule on enable.
    |
    */

    'ssh_port' => (int) env('SERVER_SSH_PORT', 22),

    /*
    |--------------------------------------------------------------------------
    | Firewall
    |--------------------------------------------------------------------------
    |
    | `default_firewall_ports` are the TCP ports seeded as `allow` rules when
    | the firewall is enabled (SSH is always added on top). These are the
    | panel's own service ports plus the web ports so enabling never locks the
    | box (or its sites) out.
    |
    */

    'default_firewall_ports' => array_map('intval', array_filter(explode(
        ',', (string) env('SERVER_DEFAULT_FIREWALL_PORTS', '80,443')
    ))),

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | The systemd units the panel manages, derived from our supported type sets
    | (web server / database / cache / worker). php-fpm units are detected at
    | runtime from `php_dir`. Only installed units are surfaced. `protected`
    | units (the panel's own web server + php-fpm) can't be stopped/disabled.
    |
    */

    'php_dir' => env('SERVER_PHP_DIR', '/etc/php'),

    'services' => [
        ['key' => 'nginx', 'unit' => 'nginx', 'label' => 'Nginx'],
        ['key' => 'apache', 'unit' => 'apache2', 'label' => 'Apache'],
        ['key' => 'openlitespeed', 'unit' => 'lshttpd', 'label' => 'OpenLiteSpeed'],
        ['key' => 'mysql', 'unit' => 'mysql', 'label' => 'MySQL'],
        ['key' => 'mariadb', 'unit' => 'mariadb', 'label' => 'MariaDB'],
        ['key' => 'mongodb', 'unit' => 'mongod', 'label' => 'MongoDB'],
        ['key' => 'redis', 'unit' => 'redis-server', 'label' => 'Redis'],
        ['key' => 'supervisor', 'unit' => 'supervisor', 'label' => 'Supervisor'],
    ],

    'protected_services' => array_values(array_filter(explode(
        ',', (string) env('SERVER_PROTECTED_SERVICES', 'nginx,php8.4-fpm')
    ))),

];
