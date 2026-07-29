<?php

use App\Services\Applications\Types\GitSiteType;
use App\Services\Applications\Types\JoomlaSiteType;
use App\Services\Applications\Types\NextcloudSiteType;
use App\Services\Applications\Types\PhpMyAdminSiteType;
use App\Services\Applications\Types\PhpSiteType;
use App\Services\Applications\Types\StaticSiteType;
use App\Services\Applications\Types\WordPressSiteType;
use App\Services\Git\BitbucketProvider;
use App\Services\Git\GithubProvider;
use App\Services\Git\GitlabProvider;
use App\Services\Server\Applications\Installers\JoomlaInstaller;
use App\Services\Server\Applications\Installers\NextcloudInstaller;
use App\Services\Server\Applications\Installers\PhpMyAdminInstaller;
use App\Services\Server\Applications\Installers\WordPressInstaller;
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
    | Cron writes a job's output nowhere useful by default — it mails it, and a
    | server with no MTA discards it silently, so a failing job leaves no trace
    | at all. Managed jobs therefore redirect into one file per job here.
    |
    | Capturing output without bounding it is a slow disk-fill: a job running
    | every minute writes forever. The logrotate drop-in below is written
    | alongside the first job and is not optional.
    */

    'cronjob_log_dir' => env('SERVER_CRONJOB_LOG_DIR', '/var/log/cronjobs'),
    'cronjob_logrotate_file' => env('SERVER_CRONJOB_LOGROTATE', '/etc/logrotate.d/panel-cronjobs'),
    'cronjob_log_keep_days' => (int) env('SERVER_CRONJOB_LOG_KEEP_DAYS', 14),
    'cronjob_log_max_size' => env('SERVER_CRONJOB_LOG_MAX_SIZE', '10M'),

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

    // How a version maps to its CLI binary, so an application's own tooling
    // runs on the same PHP that will serve it.
    'php_binary_pattern' => env('SERVER_PHP_BINARY_PATTERN', '/usr/bin/php{version}'),

    /*
    | Git deploys. The credential file is written 0600 and deleted as soon as
    | the command finishes — the token is never a command argument and never
    | reaches .git/config. Clones and builds are slow, so they get their own
    | generous timeouts rather than the 60s default.
    */

    /*
    | Marketplace installers. One entry per one-click app; a site type absent
    | from this list simply has nothing to install (git, blank PHP, static).
    | Downloads are https-only and unpacked in a temp dir before being moved
    | into the web root, so a bad archive never lands on a live site.
    */

    'installer_work_dir' => env('SERVER_INSTALLER_WORK_DIR', sys_get_temp_dir()),
    'installer_timeout' => (int) env('SERVER_INSTALLER_TIMEOUT', 300),

    'installers' => [
        'wordpress' => [
            'driver' => WordPressInstaller::class,
            'download_url' => env('SERVER_WORDPRESS_URL', 'https://wordpress.org/latest.tar.gz'),
            'salt_url' => 'https://api.wordpress.org/secret-key/1.1/salt/',
            'wp_cli' => env('SERVER_WP_CLI', '/usr/local/bin/wp'),
            'wp_cli_url' => 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar',
        ],

        'joomla' => [
            // Joomla's downloads carry the version in the filename with no
            // "latest" alias, so the installer asks which release is current
            // rather than holding a URL that 404s at the next release.
            'driver' => JoomlaInstaller::class,
            'download_url' => env('SERVER_JOOMLA_URL', ''),
            'releases_api' => env('SERVER_JOOMLA_RELEASES_API', 'https://api.github.com/repos/joomla/joomla-cms/releases/latest'),
            'db_type' => env('SERVER_JOOMLA_DB_TYPE', 'mysqli'),
            'timeout' => (int) env('SERVER_JOOMLA_TIMEOUT', 900),
        ],

        'nextcloud' => [
            'driver' => NextcloudInstaller::class,
            // Upstream publishes bzip2 and zip only — no gzip — which is why
            // the shared extract step lets tar detect the compression.
            'download_url' => env('SERVER_NEXTCLOUD_URL', 'https://download.nextcloud.com/server/releases/latest.tar.bz2'),
            'database' => env('SERVER_NEXTCLOUD_DATABASE', 'mysql'),
            // 280 MB to fetch and a schema to build afterwards. The shared
            // default would time this out on any ordinary connection.
            'timeout' => (int) env('SERVER_NEXTCLOUD_TIMEOUT', 1800),
        ],

        'phpmyadmin' => [
            'driver' => PhpMyAdminInstaller::class,
            // Redirects to the current release; both hops are https, which the
            // download step requires.
            'download_url' => env('SERVER_PHPMYADMIN_URL', 'https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz'),
            'db_host' => env('SERVER_PHPMYADMIN_DB_HOST', '127.0.0.1'),
        ],
    ],

    'git_credential_dir' => env('SERVER_GIT_CREDENTIAL_DIR', sys_get_temp_dir()),
    'git_timeout' => (int) env('SERVER_GIT_TIMEOUT', 300),
    'build_timeout' => (int) env('SERVER_BUILD_TIMEOUT', 600),

    'site_types' => [
        WordPressSiteType::class,
        NextcloudSiteType::class,
        JoomlaSiteType::class,
        PhpMyAdminSiteType::class,
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
    | fail2ban
    |--------------------------------------------------------------------------
    |
    | Brute-force containment. Config goes to a drop-in under `jail.d`, never
    | to `jail.local` — a server migrated from another panel probably owns
    | that file, and ours has no business overwriting it.
    |
    | The ban action is left to fail2ban's default rather than set to `ufw`.
    | Routing bans through UFW would mean the Firewall screen's on/off switch
    | silently disables every ban, with nothing on this screen to say so; two
    | features that each claim to be protecting the server should not have one
    | quietly turning the other off.
    |
    */

    'fail2ban' => [
        'client' => env('SERVER_FAIL2BAN_CLIENT', 'fail2ban-client'),
        'jail_d' => env('SERVER_FAIL2BAN_JAIL_D', '/etc/fail2ban/jail.d'),
        'drop_in' => env('SERVER_FAIL2BAN_DROP_IN', 'panel.local'),

        'defaults' => [
            'bantime' => (int) env('SERVER_FAIL2BAN_BANTIME', 3600),
            'findtime' => (int) env('SERVER_FAIL2BAN_FINDTIME', 600),
            'maxretry' => (int) env('SERVER_FAIL2BAN_MAXRETRY', 5),
        ],

        // The jails the panel manages. `sshd` guards the way into the server
        // and is the reason to run fail2ban at all; `recidive` watches
        // fail2ban's own log and gives repeat offenders a much longer ban.
        // Per-application jails (a WordPress login, say) belong with the
        // application, whose log path only exists once the app does.
        'jails' => [
            [
                'name' => 'sshd',
                'label' => 'SSH',
                // Enabling this one can lock the operator out of their own
                // server, so the API requires an explicit acknowledgement.
                'lockout_risk' => true,
                // `{ssh_port}` is replaced with the port SSH is really on.
                // Left as fail2ban's default it would resolve to 22, so on a
                // server whose SSH was moved — via this very panel — the ban
                // would land on a port nobody uses.
                'options' => ['mode' => 'aggressive', 'port' => '{ssh_port}'],
            ],
            [
                'name' => 'recidive',
                'label' => 'Repeat offenders',
                'lockout_risk' => false,
                'options' => [
                    'logpath' => '/var/log/fail2ban.log',
                    'bantime' => 604800,  // a week
                    'findtime' => 86400,
                    'maxretry' => 3,
                ],
            ],
        ],

        // Ban-time presets, so the form offers "1 hour" rather than 3600.
        // Backend-driven for the same reason the cron schedule presets are:
        // the frontend should not be maintaining its own copy of a list that
        // has to agree with what the API accepts. `-1` is fail2ban's
        // permanent ban.
        'bantime_presets' => [
            ['key' => '10m', 'seconds' => 600],
            ['key' => '1h', 'seconds' => 3600],
            ['key' => '1d', 'seconds' => 86400],
            ['key' => '1w', 'seconds' => 604800],
            ['key' => 'permanent', 'seconds' => -1],
        ],

        'bantime_max' => (int) env('SERVER_FAIL2BAN_BANTIME_MAX', 31536000), // a year
    ],

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

    /*
    | Per-service configuration tests. A service absent from this map has no
    | meaningful test and is reported as not testable rather than being given
    | a command that proves nothing. php-fpm is handled separately, since each
    | installed version validates itself (`php-fpm8.4 -t`).
    */

    'php_fpm_binary' => env('SERVER_PHP_FPM_BINARY', '/usr/sbin/php-fpm'),
    'php_ini_max_bytes' => (int) env('SERVER_PHP_INI_MAX_BYTES', 262144),

    'config_tests' => [
        'nginx' => ['nginx', '-t'],
        'apache' => ['apachectl', 'configtest'],
        'openlitespeed' => ['/usr/local/lsws/bin/lswsctrl', 'status'],
    ],

    'services' => [
        ['key' => 'nginx', 'unit' => 'nginx', 'label' => 'Nginx'],
        ['key' => 'apache', 'unit' => 'apache2', 'label' => 'Apache'],
        ['key' => 'openlitespeed', 'unit' => 'lshttpd', 'label' => 'OpenLiteSpeed'],
        ['key' => 'mysql', 'unit' => 'mysql', 'label' => 'MySQL'],
        ['key' => 'mariadb', 'unit' => 'mariadb', 'label' => 'MariaDB'],
        ['key' => 'mongodb', 'unit' => 'mongod', 'label' => 'MongoDB'],
        ['key' => 'redis', 'unit' => 'redis-server', 'label' => 'Redis'],
        ['key' => 'supervisor', 'unit' => 'supervisor', 'label' => 'Supervisor'],
        ['key' => 'fail2ban', 'unit' => 'fail2ban', 'label' => 'Fail2ban'],
    ],

    'protected_services' => array_values(array_filter(explode(
        ',', (string) env('SERVER_PROTECTED_SERVICES', 'nginx,php8.4-fpm')
    ))),

    /*
    | Which log sources belong to which service, as keys into the `logs`
    | registry below. This maps a service row to the existing Logs feature
    | instead of giving services a second way to read a log — same reader,
    | same permission, and the incremental cursor already there means the
    | frontend can poll for a live view. php-fpm is derived per version.
    | Keys pointing at a file that doesn't exist are dropped at request time.
    */

    'service_logs' => [
        'nginx' => ['nginx_error', 'nginx_access'],
        'fail2ban' => ['fail2ban'],
        'apache' => ['apache_error', 'apache_access'],
        'openlitespeed' => ['openlitespeed_error'],
        'mysql' => ['mysql_error', 'mysql_slow'],
        'mariadb' => ['mysql_error', 'mysql_slow'],
        'mongodb' => ['mongodb'],
        'redis' => ['redis'],
        'supervisor' => ['supervisor'],
    ],

    /*
    | Service usage sampling. CPU is a cumulative counter, so a percentage is
    | measured between two reads (see ServiceUsage): `window` is the oldest
    | previous sample still worth comparing against — beyond it the average
    | describes a period nobody is watching — and `ttl` is how long a sample
    | is kept at all.
    */

    'usage_sample_window' => (int) env('SERVER_USAGE_SAMPLE_WINDOW', 60),
    'usage_sample_ttl' => (int) env('SERVER_USAGE_SAMPLE_TTL', 300),

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
        // Cache
        ['key' => 'redis', 'label' => 'Redis', 'group' => 'cache', 'path' => '/var/log/redis/redis-server.log'],
        // System
        ['key' => 'syslog', 'label' => 'System — Syslog', 'group' => 'system', 'path' => '/var/log/syslog'],
        ['key' => 'auth', 'label' => 'System — Auth', 'group' => 'system', 'path' => '/var/log/auth.log'],
        // Security / daemons
        ['key' => 'ufw', 'label' => 'Firewall — UFW', 'group' => 'security', 'path' => '/var/log/ufw.log'],
        // Also what the `recidive` jail reads to find repeat offenders.
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

    // Whole block devices live directly under here; partitions live beneath
    // them. That distinction is what stops disk I/O being counted twice.
    'sys_block' => env('SERVER_SYS_BLOCK', '/sys/block'),

    'metrics' => [
        'sample_interval' => (int) env('SERVER_METRICS_SAMPLE_INTERVAL', 1),
        // Oldest previous poll still worth measuring against on the live
        // endpoint; past this the average describes a window nobody is
        // watching, so it reports zero instead.
        'rate_window' => (int) env('SERVER_METRICS_RATE_WINDOW', 120),
        // Device names counted for disk I/O. Restricted to real disk types so
        // loop devices (mounted files) and device-mapper nodes — whose traffic
        // is already counted on the disk underneath — are left out.
        'disk_devices' => env('SERVER_METRICS_DISK_DEVICES', '/^(sd|nvme|vd|xvd|hd|md)/'),
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
