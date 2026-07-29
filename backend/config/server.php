<?php

use App\Services\Applications\Types\GitSiteType;
use App\Services\Applications\Types\PhpSiteType;
use App\Services\Applications\Types\StaticSiteType;
use App\Services\Applications\Types\WordPressSiteType;
use App\Services\Git\BitbucketProvider;
use App\Services\Git\GithubProvider;
use App\Services\Git\GitlabProvider;
use App\Services\Server\DiskCleaner\Targets\AptCacheTarget;
use App\Services\Server\DiskCleaner\Targets\AptOrphansTarget;
use App\Services\Server\DiskCleaner\Targets\JournalTarget;
use App\Services\Server\DiskCleaner\Targets\RotatedLogsTarget;
use App\Services\Server\DiskCleaner\Targets\ServiceLogsTarget;
use App\Services\Server\DiskCleaner\Targets\TmpTarget;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;

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
    | Server timezone sources
    |--------------------------------------------------------------------------
    |
    | Cron interprets schedules in the OS timezone, so "next run" must be
    | computed there. Read from the filesystem rather than `timedatectl` —
    | this is called once per row in a list response, and a process per row
    | would make a cheap endpoint expensive. Overridable so tests can point
    | at a fixture.
    |
    */

    'timezone_file' => env('SERVER_TIMEZONE_FILE', '/etc/timezone'),
    'localtime_link' => env('SERVER_LOCALTIME_LINK', '/etc/localtime'),

    /*
    |--------------------------------------------------------------------------
    | Server capability detection
    |--------------------------------------------------------------------------
    |
    | Fallback probes for when the installation script never ran (a server
    | migrated in from another panel). Only one web server can own :80, so the
    | first directory that exists wins. Overridable so tests can point at
    | temp fixtures.
    |
    */

    'web_servers' => [
        'nginx' => ['/etc/nginx'],
        'apache' => ['/etc/apache2', '/etc/httpd'],
        'openlitespeed' => ['/usr/local/lsws'],
    ],

    'node_binary' => env('SERVER_NODE_BINARY', 'node'),

    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    |
    | The site-type catalog. Each class declares its own fields, so the create
    | form is rendered from data and a new type costs one class and one line
    | here — no frontend change.
    |
    */

    /*
    | Web servers the panel can write config for. A server running something
    | not listed here is refused rather than guessed at — a config we invented
    | would fail its own test at best, and take every site down at worst.
    | OpenLiteSpeed is absent deliberately: its config format is unlike the
    | others and deserves its own implementation, not a near-miss.
    */

    'web_server_drivers' => [
        'nginx' => [
            'driver' => NginxDriver::class,
            'sites_dir' => env('SERVER_NGINX_SITES_DIR', '/etc/nginx/sites-enabled'),
        ],
        'apache' => [
            'driver' => ApacheDriver::class,
            'sites_dir' => env('SERVER_APACHE_SITES_DIR', '/etc/apache2/sites-enabled'),
        ],
    ],

    'default_php_version' => env('SERVER_DEFAULT_PHP_VERSION', '8.4'),

    'site_types' => [
        WordPressSiteType::class,
        GitSiteType::class,
        PhpSiteType::class,
        StaticSiteType::class,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Read-only server log sources the panel surfaces. No DB — the catalog is
    | filtered at request time to the files that actually exist on the box
    | (detect-don't-trust). php-fpm logs are detected per installed version
    | from `php_dir`. The client only ever references a source by its `key`;
    | the panel resolves the path from this registry (never a client path).
    |
    */

    'logs' => [
        // Web server (only the installed one has files)
        ['key' => 'nginx_access', 'label' => 'Nginx — Access', 'group' => 'web', 'path' => '/var/log/nginx/access.log'],
        ['key' => 'nginx_error', 'label' => 'Nginx — Error', 'group' => 'web', 'path' => '/var/log/nginx/error.log'],
        ['key' => 'apache_access', 'label' => 'Apache — Access', 'group' => 'web', 'path' => '/var/log/apache2/access.log'],
        ['key' => 'apache_error', 'label' => 'Apache — Error', 'group' => 'web', 'path' => '/var/log/apache2/error.log'],
        ['key' => 'openlitespeed_error', 'label' => 'OpenLiteSpeed — Error', 'group' => 'web', 'path' => '/usr/local/lsws/logs/error.log'],
        // Database (installed engine)
        ['key' => 'mysql_error', 'label' => 'MySQL — Error', 'group' => 'database', 'path' => '/var/log/mysql/error.log'],
        ['key' => 'mysql_slow', 'label' => 'MySQL — Slow Query', 'group' => 'database', 'path' => '/var/log/mysql/mariadb-slow.log'],
        ['key' => 'mongodb', 'label' => 'MongoDB', 'group' => 'database', 'path' => '/var/log/mongodb/mongod.log'],
        // System
        ['key' => 'syslog', 'label' => 'System — Syslog', 'group' => 'system', 'path' => '/var/log/syslog'],
        ['key' => 'auth', 'label' => 'System — Auth', 'group' => 'system', 'path' => '/var/log/auth.log'],
        // Security / daemons
        ['key' => 'ufw', 'label' => 'Firewall — UFW', 'group' => 'security', 'path' => '/var/log/ufw.log'],
        ['key' => 'fail2ban', 'label' => 'Fail2ban', 'group' => 'security', 'path' => '/var/log/fail2ban.log'],
        ['key' => 'supervisor', 'label' => 'Supervisor', 'group' => 'daemon', 'path' => '/var/log/supervisor/supervisord.log'],
    ],

    /*
    | The path pattern for per-version php-fpm logs. `{version}` is replaced
    | with each installed PHP version detected from `php_dir`.
    */

    'php_fpm_log' => env('SERVER_PHP_FPM_LOG', '/var/log/php{version}-fpm.log'),

    /*
    |--------------------------------------------------------------------------
    | Disk Cleaner
    |--------------------------------------------------------------------------
    |
    | Server-level disk-cleanup. `disk_path` is the filesystem reported by df.
    | `targets` are the pluggable CleanupTarget strategies (each detect-gated;
    | only those whose dependency is present surface). Retention windows and
    | the service-log glob set are config so they can be tuned per box.
    |
    */

    'disk_path' => env('SERVER_DISK_PATH', '/'),

    'disk_cleaner' => [
        'journal_days' => (int) env('SERVER_DISK_JOURNAL_DAYS', 7),
        'tmp_days' => (int) env('SERVER_DISK_TMP_DAYS', 7),

        'targets' => [
            AptCacheTarget::class,
            AptOrphansTarget::class,
            JournalTarget::class,
            RotatedLogsTarget::class,
            ServiceLogsTarget::class,
            TmpTarget::class,
        ],

        // Active service log files truncated by `service_logs` (glob patterns;
        // only existing files are touched). Covers our supported services.
        'service_log_globs' => [
            '/var/log/nginx/*.log',
            '/var/log/apache2/*.log',
            '/usr/local/lsws/logs/*.log',
            '/var/log/mysql/*.log',
            '/var/log/mongodb/*.log',
            '/var/log/redis/*.log',
            '/var/log/supervisor/*.log',
            '/var/log/php*-fpm.log',
            '/var/log/ufw.log',
            '/var/log/fail2ban.log',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Paths + option sets for the server Settings feature. Changes are written
    | to managed, NON-DESTRUCTIVE drop-ins (never the distro's own config), so
    | a migrated server keeps its existing configuration. Overridable so tests
    | can point them at writable temp paths.
    |
    */

    'sshd_config_dir' => env('SERVER_SSHD_CONFIG_DIR', '/etc/ssh/sshd_config.d'),

    'unattended_upgrades_file' => env('SERVER_UNATTENDED_UPGRADES', '/etc/apt/apt.conf.d/99-panel-upgrades'),

    'reboot_required_file' => env('SERVER_REBOOT_REQUIRED', '/var/run/reboot-required'),

    'redis_cli' => env('SERVER_REDIS_CLI', '/usr/bin/redis-cli'),

    'redis_maxmemory_policies' => [
        'noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random',
        'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl',
    ],

    // Swap: a single managed swap file. Only this file + its fstab line are
    // ever touched (non-destructive → a migrated server keeps existing swap).
    'swap_file' => env('SERVER_SWAP_FILE', '/swapfile'),

    'swap_max_mb' => (int) env('SERVER_SWAP_MAX_MB', 65536), // 64 GB ceiling

    'fstab' => env('SERVER_FSTAB', '/etc/fstab'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard / metrics
    |--------------------------------------------------------------------------
    |
    | Server dashboard reads facts + live metrics cheaply from `/proc` (+ df).
    | `sample_interval` is the short window (seconds) used to compute the rate
    | for cumulative counters (CPU %, network) — two reads that far apart.
    | `metrics.retention_hours` bounds the `server_metrics` table (the 5-min
    | collector prunes anything older). All overridable so tests can point
    | `proc_dir`/`os_release` at fixtures and set the interval to 0.
    |
    */

    'proc_dir' => env('SERVER_PROC_DIR', '/proc'),

    'os_release' => env('SERVER_OS_RELEASE', '/etc/os-release'),

    'metrics' => [
        'sample_interval' => (int) env('SERVER_METRICS_SAMPLE_INTERVAL', 1),
        'retention_hours' => (int) env('SERVER_METRICS_RETENTION_HOURS', 24),
        'processes_limit' => (int) env('SERVER_METRICS_PROCESSES_LIMIT', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Databases
    |--------------------------------------------------------------------------
    |
    | The Databases feature manages MySQL / MariaDB / MongoDB via a
    | DatabaseEngine strategy. The per-engine admin connection lives in the
    | `database_connections` table (NOT here) — this file only holds the
    | static engine capabilities, client binaries, protected system objects,
    | and the charset/collation whitelist (identifiers can't be parameterised
    | in DDL, so the whitelist IS the injection guard). Client names + the
    | auth-file dir are overridable so tests can fake the Process calls.
    |
    */

    'databases' => [
        'engines' => [
            'mysql' => ['driver' => 'sql', 'client' => env('SERVER_MYSQL_CLIENT', 'mysql'), 'dump_client' => env('SERVER_MYSQLDUMP', 'mysqldump'), 'default_port' => 3306, 'default_socket' => '/var/run/mysqld/mysqld.sock'],
            'mariadb' => ['driver' => 'sql', 'client' => env('SERVER_MARIADB_CLIENT', 'mariadb'), 'dump_client' => env('SERVER_MARIADBDUMP', 'mariadb-dump'), 'default_port' => 3306, 'default_socket' => '/var/run/mysqld/mysqld.sock'],
            'mongodb' => ['driver' => 'mongo', 'client' => env('SERVER_MONGO_CLIENT', 'mongosh'), 'dump_client' => env('SERVER_MONGODUMP', 'mongodump'), 'default_port' => 27017, 'default_socket' => null],
        ],

        // Never created/dropped/altered by the panel (deny-by-default guard).
        'system_schemas' => [
            'sql' => ['information_schema', 'mysql', 'performance_schema', 'sys'],
            'mongo' => ['admin', 'config', 'local'],
        ],
        'system_users' => ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'debian-sys-maint', 'mariadb.sys'],

        // Whitelist for validation — charset => allowed collations.
        'charsets' => [
            'utf8mb4' => ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_bin'],
            'utf8mb3' => ['utf8mb3_general_ci', 'utf8mb3_unicode_ci'],
            'latin1' => ['latin1_swedish_ci', 'latin1_general_ci'],
            'ascii' => ['ascii_general_ci'],
        ],
        'default_charset' => 'utf8mb4',
        'default_collation' => 'utf8mb4_unicode_ci',

        // 0600 temp auth files (--defaults-extra-file) are written here so a DB
        // password is never passed on argv (visible in `ps`).
        'auth_file_dir' => env('SERVER_DB_AUTH_DIR', sys_get_temp_dir()),

        // Where database exports (dumps) are written + streamed from.
        'export_dir' => env('SERVER_DB_EXPORT_DIR', storage_path('app/db-exports')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Git provider integrations
    |--------------------------------------------------------------------------
    |
    | Connected accounts used to reach private repositories. `fields` is the
    | connect-form schema the API hands the frontend, because the providers
    | genuinely differ: GitLab can point at a self-hosted host, and Bitbucket
    | addresses everything through a workspace (its scoped Access Tokens
    | authenticate as the token, not as a user).
    |
    | Outbound calls are bounded — a provider being slow must never hold a
    | request open.
    |
    */

    'git' => [
        'connect_timeout' => 3,
        'timeout' => 5,
        'max_redirects' => 3,
        'per_page' => 30,

        'providers' => [
            'github' => [
                'driver' => GithubProvider::class,
                'api' => 'https://api.github.com',
                'fields' => [
                    'token' => ['required' => true, 'type' => 'password'],
                ],
            ],
            'gitlab' => [
                'driver' => GitlabProvider::class,
                'api' => 'https://gitlab.com',
                'fields' => [
                    'token' => ['required' => true, 'type' => 'password'],
                    'host' => ['required' => false, 'type' => 'url'],
                ],
            ],
            'bitbucket' => [
                'driver' => BitbucketProvider::class,
                'api' => 'https://api.bitbucket.org',
                'fields' => [
                    'workspace' => ['required' => true, 'type' => 'text'],
                    'token' => ['required' => true, 'type' => 'password'],
                ],
            ],
        ],
    ],

];
