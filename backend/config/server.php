<?php

use App\Services\Applications\Types\AkauntingSiteType;
use App\Services\Applications\Types\CraftCmsSiteType;
use App\Services\Applications\Types\GitSiteType;
use App\Services\Applications\Types\JoomlaSiteType;
use App\Services\Applications\Types\MauticSiteType;
use App\Services\Applications\Types\MoodleSiteType;
use App\Services\Applications\Types\N8nSiteType;
use App\Services\Applications\Types\NextcloudSiteType;
use App\Services\Applications\Types\NodeBbSiteType;
use App\Services\Applications\Types\NodeRedSiteType;
use App\Services\Applications\Types\PhpMyAdminSiteType;
use App\Services\Applications\Types\PhpSiteType;
use App\Services\Applications\Types\PrestaShopSiteType;
use App\Services\Applications\Types\StatamicSiteType;
use App\Services\Applications\Types\StaticSiteType;
use App\Services\Applications\Types\UptimeKumaSiteType;
use App\Services\Applications\Types\WordPressSiteType;
use App\Services\Git\BitbucketProvider;
use App\Services\Git\GithubProvider;
use App\Services\Git\GitlabProvider;
use App\Services\Git\Webhooks\BitbucketWebhook;
use App\Services\Git\Webhooks\GithubWebhook;
use App\Services\Git\Webhooks\GitlabWebhook;
use App\Services\Server\Applications\Installers\AkauntingInstaller;
use App\Services\Server\Applications\Installers\CraftCmsInstaller;
use App\Services\Server\Applications\Installers\JoomlaInstaller;
use App\Services\Server\Applications\Installers\MauticInstaller;
use App\Services\Server\Applications\Installers\MoodleInstaller;
use App\Services\Server\Applications\Installers\N8nInstaller;
use App\Services\Server\Applications\Installers\NextcloudInstaller;
use App\Services\Server\Applications\Installers\NodeBbInstaller;
use App\Services\Server\Applications\Installers\NodeRedInstaller;
use App\Services\Server\Applications\Installers\PhpMyAdminInstaller;
use App\Services\Server\Applications\Installers\PrestaShopInstaller;
use App\Services\Server\Applications\Installers\StatamicInstaller;
use App\Services\Server\Applications\Installers\UptimeKumaInstaller;
use App\Services\Server\Applications\Installers\WordPressInstaller;
use App\Services\Server\Backups\Steps\ArchiveFiles;
use App\Services\Server\Backups\Steps\DumpDatabase;
use App\Services\Server\Backups\Steps\PruneOldBackups;
use App\Services\Server\Backups\Steps\UploadArtifact;
use App\Services\Server\Backups\Steps\VerifyArtifact;
use App\Services\Server\Databases\Installers\MariaDbInstaller;
use App\Services\Server\Databases\Installers\MySqlInstaller;
use App\Services\Server\DiskCleaner\Targets\AptCacheTarget;
use App\Services\Server\DiskCleaner\Targets\AptOrphansTarget;
use App\Services\Server\DiskCleaner\Targets\JournalTarget;
use App\Services\Server\DiskCleaner\Targets\RotatedLogsTarget;
use App\Services\Server\DiskCleaner\Targets\ServiceLogsTarget;
use App\Services\Server\DiskCleaner\Targets\TmpTarget;
use App\Services\Server\Doctor\Checks\AccountLocksCheck;
use App\Services\Server\Doctor\Checks\BinariesCheck;
use App\Services\Server\Doctor\Checks\DatabaseCheck;
use App\Services\Server\Doctor\Checks\FrontendBuildCheck;
use App\Services\Server\Doctor\Checks\HealthEndpointCheck;
use App\Services\Server\Doctor\Checks\PhpIsolationCheck;
use App\Services\Server\Doctor\Checks\PrivilegeCheck;
use App\Services\Server\Doctor\Checks\QueueCheck;
use App\Services\Server\Doctor\Checks\ServicesCheck;
use App\Services\Server\Doctor\Checks\WebServerCheck;
use App\Services\Server\Doctor\Checks\WritablePathsCheck;
use App\Services\Server\Php\Stacks\FpmPhpStack;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\Restores\Steps\DownloadArtifact;
use App\Services\Server\Restores\Steps\ExtractArchive;
use App\Services\Server\Restores\Steps\RestartProcess;
use App\Services\Server\Restores\Steps\RestoreDatabase;
use App\Services\Server\Restores\Steps\SafetyBackup;
use App\Services\Server\Restores\Steps\SwapFiles;
use App\Services\Server\Restores\Steps\VerifyDownload;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Privilege escalation
    |--------------------------------------------------------------------------
    |
    | php-fpm runs as the unprivileged panel account (install.sh sets
    | `user = ${APP_USER}` on the pool), so every operation that touches the
    | system — useradd, systemctl, ufw, apt-get, writing a vhost — has to go
    | through sudo. install.sh grants exactly these binaries NOPASSWD in
    | /etc/sudoers.d/<slug>; this list mirrors it and must stay in step with
    | it, because a binary here that is not there fails at runtime, and one
    | there but not here is a privilege granted for no reason.
    |
    | Prefixing happens in ServerOps, in one place, rather than at the 60+
    | call sites. Commands stay arrays — no shell, no interpolation, so this
    | adds no injection surface.
    |
    | `enabled` is false under phpunit (see phpunit.xml): the suite asserts on
    | the commands it expects a feature to run, and a sudo prefix everywhere
    | would test the prefix rather than the feature. ServerOpsPrivilegeTest
    | turns it on and covers the prefixing itself.
    |
    */

    'privilege' => [
        'sudo' => (bool) env('SERVER_OPS_SUDO', true),

        // Binaries that do not work as the panel user. Mirrors the `bins`
        // array in install.sh's configure_sudoers().
        'binaries' => [
            'apt-get', 'apt-cache', 'dpkg-query',
            'systemctl', 'journalctl',
            'useradd', 'userdel', 'usermod', 'groupadd',
            'chpasswd', 'gpasswd', 'getent', 'id',
            'tee', 'mkdir', 'chown', 'chmod', 'rm',
            'cp', 'mv', 'ln', 'install', 'truncate',
            'find', 'tail', 'cat', 'test', 'which',
            'runuser', 'sh', 'env',
            'nginx', 'apachectl', 'lswsctrl',
            'phpenmod', 'phpdismod', 'update-alternatives',
            'mysql', 'redis-cli', 'mongosh',
            'ufw', 'fail2ban-client',
            'fallocate', 'mkswap', 'swapon', 'swapoff',
            'hostnamectl', 'timedatectl', 'df', 'du',
            'ps', 'kill', 'ss', 'curl', 'unzip',
            'tar', 'git', 'fnm', 'wp',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Ordered steps of one backup run. Order is the safety property: prune is
    | last and only runs after verify, so the panel never deletes an old backup
    | to make room for one that then fails.
    |
    */

    'backups' => [
        'steps' => [
            DumpDatabase::class,
            ArchiveFiles::class,
            UploadArtifact::class,
            VerifyArtifact::class,
            PruneOldBackups::class,
        ],

        // Restore stages, in order. Everything before `restore_database` is
        // non-destructive on purpose: the download, the archive check and the
        // safety backup all fail with the live application untouched.
        'restore_steps' => [
            DownloadArtifact::class,
            VerifyDownload::class,
            SafetyBackup::class,
            ExtractArchive::class,
            RestoreDatabase::class,
            SwapFiles::class,
            RestartProcess::class,
        ],

        // Dumps and archives are written here before upload. Under storage/
        // rather than /tmp: /tmp is cleared on reboot and is often a small
        // tmpfs, and a multi-gigabyte site archive would fill it.
        'working_dir' => env('BACKUP_WORKING_DIR', storage_path('app/backups')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-check (doctor)
    |--------------------------------------------------------------------------
    |
    | Checks run against the real server by `panel:doctor` and the admin
    | self-check screen. Order is display order, cheapest and most fundamental
    | first: if privilege fails, most of the rest is noise.
    |
    */

    'doctor' => [
        'checks' => [
            PrivilegeCheck::class,
            BinariesCheck::class,
            AccountLocksCheck::class,
            ServicesCheck::class,
            WebServerCheck::class,
            FrontendBuildCheck::class,
            WritablePathsCheck::class,
            DatabaseCheck::class,
            QueueCheck::class,
            PhpIsolationCheck::class,
            HealthEndpointCheck::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transient failures
    |--------------------------------------------------------------------------
    |
    | Some failures mean "busy, nothing happened" rather than "wrong". The
    | account tools take a lock on /etc/passwd and apt takes one on the dpkg
    | database, and both refuse immediately if anything else holds it — which
    | is routine on a server that is also running unattended-upgrades, or one
    | where the installer has only just finished.
    |
    | Retrying is safe precisely because the command never started: the lock
    | is taken before any change. Only patterns with that guarantee belong
    | here. A failure that might have half-completed must not be retried.
    |
    | Without this the operator sees a hard error for something that would
    | have worked a second later, and has no way to tell the difference.
    |
    */

    'transient' => [
        'attempts' => (int) env('SERVER_OPS_RETRIES', 3),
        'delay_ms' => (int) env('SERVER_OPS_RETRY_DELAY_MS', 1500),

        // A lock that survives every retry is not "busy" — the holder is a
        // corpse and no amount of waiting helps. Telling the operator to try
        // again is advice that can never come true, so these get their own
        // code and their own message. `panel:doctor` names the files.
        'stale_lock_patterns' => [
            'cannot lock',
        ],

        // Matched case-insensitively against stderr.
        'patterns' => [
            // useradd/usermod/userdel/groupadd, when another account tool
            // (or a package postinst) holds the passwd/group lock.
            'cannot lock',
            'try again later',
            // apt/dpkg.
            'could not get lock',
            'unable to acquire the dpkg frontend lock',
            'is another process using it',
            // Generic EAGAIN from a lock file.
            'resource temporarily unavailable',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account operation serialization
    |--------------------------------------------------------------------------
    |
    | The OS exposes a single lock over /etc/passwd (shared by useradd, userdel,
    | usermod, gpasswd, chpasswd, passwd). Two of those running at once collide
    | with "cannot lock /etc/passwd". The panel funnels every account command
    | through one shared app-level lock so they run strictly one at a time.
    |
    | ttl:  hold ceiling if the holder is killed mid-command (normally released
    |       the instant the command finishes). MUST exceed the slowest account
    |       command including ServerOps' transient retries (command timeout x
    |       attempts) or the lock would expire mid-command and let a queued op
    |       collide. Raise it if you raise SERVER_OPS_RETRIES a lot.
    | wait: how long a queued command waits for the lock before returning
    |       503 "busy, try again" instead of running.
    |
    */

    'account_lock' => [
        'key' => 'account:mutation',
        'ttl' => (int) env('SERVER_ACCOUNT_LOCK_TTL', 600),
        'wait' => (int) env('SERVER_ACCOUNT_LOCK_WAIT', 60),
    ],

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
            // Where the vhost templates point `access_log` / `error_log`.
            'log_dir' => env('SERVER_NGINX_LOG_DIR', '/var/log/nginx'),
            // Which PHP stack this web server implies — see `php_stacks`.
            'php_stack' => 'fpm',
        ],
        'apache' => [
            'driver' => ApacheDriver::class,
            'sites_dir' => env('SERVER_APACHE_SITES_DIR', '/etc/apache2/sites-enabled'),
            // `${APACHE_LOG_DIR}` in the templates, resolved — Apache expands
            // it from envvars at start, which we cannot read.
            'log_dir' => env('SERVER_APACHE_LOG_DIR', '/var/log/apache2'),
            'php_stack' => 'fpm',
        ],

        /*
        | OpenLiteSpeed. Unlike the other two, a site is a directory under
        | `vhost_root` *and* two entries in `shared_config` — see OlsSharedConfig
        | for why that file is edited by marked region rather than appended to.
        |
        | Every value here is unverified against a real OLS box; they come from
        | LiteSpeed's docs. They are config precisely so a wrong one is an edit
        | rather than a patch.
        */
        'openlitespeed' => [
            'driver' => OlsDriver::class,
            'label' => 'OpenLiteSpeed',
            /*
            | No `site_types` list, so no restriction — the same as nginx and
            | Apache. An audit found nothing in any installer or site type that
            | depends on the web server: none of them mention .htaccess, mod
            | rewrite or Apache, none declare extension requirements, and the
            | SiteType contract has no web-server concept at all. All ten
            | installers ask the PHP stack where the interpreter is.
            |
            | A shorter list here was a guess about risk wearing the costume of
            | a capability limit. If a type is genuinely unsupported on a web
            | server, list the supported ones here and the grid will grey the
            | rest with a reason.
            */
            'vhost_root' => env('SERVER_OLS_VHOST_ROOT', '/usr/local/lsws/conf/vhosts'),
            'shared_config' => env('SERVER_OLS_CONFIG', '/usr/local/lsws/conf/httpd_config.conf'),
            // A `map` is only legal inside a listener, and this names which.
            'listener' => env('SERVER_OLS_LISTENER', 'Default'),

            // OpenLiteSpeed binds certificates to a listener rather than to a
            // vhost, so a site only answers on 443 once it is mapped here too.
            // A box that has never had TLS legitimately has no such listener,
            // and registration skips it rather than failing.
            'ssl_listener' => env('SERVER_OLS_SSL_LISTENER', 'Defaultssl'),
            'test_command' => ['/usr/local/lsws/bin/lswsctrl', 'config_test'],
            // Restart, not reload: nothing lighter picks up a new virtual host,
            // and it is graceful — old workers drain rather than being cut off.
            'reload_command' => ['/usr/local/lsws/bin/lswsctrl', 'restart'],
            'php_stack' => 'lsphp',
        ],
    ],

    'default_php_version' => env('SERVER_DEFAULT_PHP_VERSION', '8.4'),

    /*
    | Where PHP-FPM puts its sockets, and the account the web server runs as.
    |
    | The web server user matters because a pool's socket has to be reachable
    | by it: the isolation comes from the pool's `user`, not from the socket's
    | permissions, so this is deliberately the shared account.
    */

    'php_socket_dir' => env('SERVER_PHP_SOCKET_DIR', '/run/php'),
    'web_server_user' => env('SERVER_WEB_SERVER_USER', 'www-data'),

    // How a version maps to its CLI binary, so an application's own tooling
    // runs on the same PHP that will serve it.
    'php_binary_pattern' => env('SERVER_PHP_BINARY_PATTERN', '/usr/bin/php{version}'),

    // Applications distributed through Composer are built rather than
    // unpacked, so it has to be present for those cards to work.
    'composer_binary' => env('SERVER_COMPOSER_BINARY', 'composer'),

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

    /*
    | Applications that run their own process (Node, and anything else with a
    | start command).
    |
    | The range sits **below the ephemeral range** the kernel hands to outgoing
    | connections (`net.ipv4.ip_local_port_range`, 32768–60999 on a stock
    | Linux). An application parked inside that range would work until the
    | moment the kernel handed the same port to something else — an
    | intermittent failure with no obvious cause. The IANA dynamic range
    | (49152+) is exactly the wrong choice for this, for the same reason.
    |
    | Ports that `/etc/services` names are skipped at allocation, so a site
    | never lands on 3306 and collides with a MySQL installed next week.
    | `reserved_ports` is for anything the OS does not know about.
    |
    | `memory_max` is per app: low enough that one runaway site cannot take the
    | box down, high enough that an ordinary Node app is not killed
    | mid-request. Chosen from common practice, not measurement — revisit when
    | there is real traffic.
    */

    'applications' => [
        'systemd_dir' => env('SERVER_SYSTEMD_DIR', '/etc/systemd/system'),
        'port_range' => [
            'from' => (int) env('SERVER_APP_PORT_FROM', 3000),
            'to' => (int) env('SERVER_APP_PORT_TO', 3999),
        ],
        'reserved_ports' => [],
        'memory_max' => env('SERVER_APP_MEMORY_MAX', '512M'),
    ],

    /*
    | Cloudflare's published address ranges.
    |
    | Held here rather than fetched. A domain pointing at Cloudflare resolves
    | perfectly well but does not reach this server, so HTTP validation fails
    | for a reason no error message mentions — it is the most common SSL
    | support question on panels of this kind. Recognising the address lets the
    | panel say so before anyone spends a Let's Encrypt attempt on it.
    |
    | A network call in the middle of a form submission is a worse failure than
    | a list that is a few months stale; these change rarely. Current list:
    | https://www.cloudflare.com/ips-v4
    */
    /*
    |--------------------------------------------------------------------------
    | TLS certificates
    |--------------------------------------------------------------------------
    |
    | certbot is driven in `certonly --webroot` mode, never through the
    | `--nginx` / `--apache` plugins. The plugins work by editing the vhost —
    | and the panel regenerates that file on every domain change, so their edits
    | would be silently wiped and the site would lose HTTPS with nothing to show
    | why. Issuing the files and writing the directives ourselves keeps one
    | owner for the config. It is also the only mode that works on
    | OpenLiteSpeed, which has no certbot plugin at all: one code path, three
    | web servers.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Deployments
    |--------------------------------------------------------------------------
    |
    | A site with auto-deploy on a busy repository deploys dozens of times a
    | day, and every row carries command output. Left unbounded this is the
    | table that quietly fills a self-hosted SQLite database, so only the newest
    | are kept — pruned on write rather than by a scheduled command, because the
    | growth is caused by deploying and the fix belongs where the growth is.
    |
    | Fifty is roughly a fortnight on an active project, which covers the only
    | question this screen answers: what changed recently. Older deploys cannot
    | be rolled back to (there are no release directories), and the commit
    | history lives in git regardless.
    |
    */

    'deployments' => [
        'keep' => (int) env('SV_DEPLOYMENTS_KEEP', 50),

        // What a new git application's deploy script starts as. A starting
        // point rather than a policy: it is the user's file the moment they
        // open the screen.
        'default_scripts' => [
            'php' => "cd {path}\ngit pull origin {branch}\n",
            'node' => "cd {path}\ngit pull origin {branch}\nnpm ci\nnpm run build --if-present\n",
            'static' => "cd {path}\ngit pull origin {branch}\n",
            'proxy' => "cd {path}\ngit pull origin {branch}\n",
        ],
    ],

    'certificates' => [
        'certbot' => env('SV_CERTBOT_BIN', 'certbot'),

        // One challenge directory shared by every site, aliased into all nine
        // vhost templates. Per-site document roots would not work for the node
        // and proxy profiles, which serve nothing from disk — there is no
        // directory for certbot to drop the token in.
        'challenge_root' => env('SV_ACME_CHALLENGE_ROOT', '/var/www/.well-known-acme'),

        'live_dir' => env('SV_LETSENCRYPT_LIVE_DIR', '/etc/letsencrypt/live'),

        // Where certbot runs its post-renewal commands. The renewal itself is
        // certbot's own systemd timer; without a hook here the new certificate
        // sits on disk while the web server keeps serving the old one from
        // memory until something happens to reload it.
        'renewal_hook_dir' => env('SV_LETSENCRYPT_HOOK_DIR', '/etc/letsencrypt/renewal-hooks/deploy'),

        // Uploaded and self-signed certificates. Not under /etc/letsencrypt —
        // certbot owns that tree and prunes what it does not recognise.
        'custom_dir' => env('SV_CUSTOM_CERT_DIR', '/etc/ssl/sv-oss'),

        // certbot can sit through two DNS lookups, an HTTP round trip and a
        // retry. The default 60s ServerOps timeout runs out in the middle of a
        // successful issuance and leaves a certificate on disk that the panel
        // thinks failed.
        'timeout' => 180,

        // Try for a certificate on its own once a site is provisioned, when
        // the domain already points here — a migrated site, or a record set in
        // advance. Off makes the panel wait to be asked, which is the right
        // choice on a box with no public DNS. It declines silently either way:
        // a decline writes nothing, so a new site never opens on a red error
        // about SSL the user has not set up yet.
        'auto_issue' => env('SV_AUTO_ISSUE_CERTIFICATES', true),

        // Warn this far out. Let's Encrypt certificates last 90 days and renew
        // at 30; a warning any earlier is noise, any later is not a warning.
        'expiry_warning_days' => 14,
    ],

    'cloudflare_ranges' => [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ],

    'installer_work_dir' => env('SERVER_INSTALLER_WORK_DIR', sys_get_temp_dir()),
    'installer_timeout' => (int) env('SERVER_INSTALLER_TIMEOUT', 300),

    /*
    | Everything a provision or deploy does *besides* the slow part: the
    | directory, ownership, the vhost write/test/reload, creating the database,
    | and starting the unit. Added to the installer (or git + build) timeout to
    | size the queued job, because a job killed at its own timeout leaves a
    | half-applied server change with nothing but "worker" to point at.
    */
    'job_overhead' => (int) env('SERVER_JOB_OVERHEAD', 120),

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

        'prestashop' => [
            'driver' => PrestaShopInstaller::class,
            // PrestaShop's own channel feed, not GitHub: their 9.x tags ship
            // no package, and this feed is what their updater follows — so a
            // new stable branch is picked up without a code change.
            'download_url' => env('SERVER_PRESTASHOP_URL', ''),
            'channel_feed' => env('SERVER_PRESTASHOP_FEED', 'https://api.prestashop.com/xml/channel.xml'),
            'timeout' => (int) env('SERVER_PRESTASHOP_TIMEOUT', 1800),
        ],

        'statamic' => [
            // Composer-built and flat-file: no archive, and no database.
            'driver' => StatamicInstaller::class,
            'timeout' => (int) env('SERVER_STATAMIC_TIMEOUT', 1800),
        ],

        'akaunting' => [
            'driver' => AkauntingInstaller::class,
            // Zip only, versioned filename — resolved rather than hardcoded.
            'download_url' => env('SERVER_AKAUNTING_URL', ''),
            'releases_api' => env('SERVER_AKAUNTING_RELEASES_API', 'https://api.github.com/repos/akaunting/akaunting/releases/latest'),
            'timeout' => (int) env('SERVER_AKAUNTING_TIMEOUT', 1800),
        ],

        'craftcms' => [
            // Distributed through Composer — there is no tarball, so this one
            // builds the application rather than unpacking it.
            'driver' => CraftCmsInstaller::class,
            'timeout' => (int) env('SERVER_CRAFTCMS_TIMEOUT', 1800),
        ],

        'mautic' => [
            'driver' => MauticInstaller::class,
            // Mautic publishes zip only, and versioned — so the release is
            // resolved, and the full package taken rather than the update
            // package, which carries changed files alone.
            'download_url' => env('SERVER_MAUTIC_URL', ''),
            'releases_api' => env('SERVER_MAUTIC_RELEASES_API', 'https://api.github.com/repos/mautic/mautic/releases/latest'),
            'config_dir' => env('SERVER_MAUTIC_CONFIG_DIR', 'config'),
            'timeout' => (int) env('SERVER_MAUTIC_TIMEOUT', 1800),
        ],

        'moodle' => [
            'driver' => MoodleInstaller::class,
            // The branch is part of the path, so a new major release means a
            // new value here rather than a silent download of the old one.
            'download_url' => env('SERVER_MOODLE_URL', 'https://packaging.moodle.org/stable500/moodle-latest-500.tgz'),
            // 75 MB, then a schema of several hundred tables to build.
            'timeout' => (int) env('SERVER_MOODLE_TIMEOUT', 1800),
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

        /*
        | The Node applications. Distributed as git repositories or npm
        | packages rather than release archives, so these do not use the
        | shared download-and-extract helper.
        |
        | `version` is pinned to a range rather than a number: `latest` on a
        | major-version boundary is how an install silently becomes an upgrade
        | nobody asked for.
        */

        'uptimekuma' => [
            'driver' => UptimeKumaInstaller::class,
            'repository' => env('SERVER_UPTIME_KUMA_REPO', 'https://github.com/louislam/uptime-kuma.git'),
            'branch' => env('SERVER_UPTIME_KUMA_BRANCH', '2.0.0'),
            // Clone plus a full frontend build.
            'timeout' => (int) env('SERVER_UPTIME_KUMA_TIMEOUT', 1800),
        ],

        'nodered' => [
            'driver' => NodeRedInstaller::class,
            'version' => env('SERVER_NODE_RED_VERSION', '4'),
            'timeout' => (int) env('SERVER_NODE_RED_TIMEOUT', 900),
        ],

        'n8n' => [
            'driver' => N8nInstaller::class,
            'version' => env('SERVER_N8N_VERSION', '1'),
            // The largest npm install in the catalog by some distance.
            'timeout' => (int) env('SERVER_N8N_TIMEOUT', 1800),
        ],

        'nodebb' => [
            'driver' => NodeBbInstaller::class,
            'repository' => env('SERVER_NODEBB_REPO', 'https://github.com/NodeBB/NodeBB.git'),
            'branch' => env('SERVER_NODEBB_BRANCH', 'v4.x'),
            'timeout' => (int) env('SERVER_NODEBB_TIMEOUT', 1800),
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
        MoodleSiteType::class,
        MauticSiteType::class,
        CraftCmsSiteType::class,
        AkauntingSiteType::class,
        StatamicSiteType::class,
        PrestaShopSiteType::class,
        PhpMyAdminSiteType::class,
        UptimeKumaSiteType::class,
        N8nSiteType::class,
        NodeRedSiteType::class,
        NodeBbSiteType::class,
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

    'firewall' => [
        // Ports worth a warning that aren't a database engine we manage —
        // those are derived from what's installed. These are the ones that
        // hand over the machine, or a large part of it, if reached from the
        // internet.
        'risky_ports' => [
            6379 => 'Redis',
            11211 => 'Memcached',
            9200 => 'Elasticsearch',
            5672 => 'RabbitMQ',
            2375 => 'Docker',
            25 => 'SMTP',
        ],
    ],

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
    | How PHP is served, per web server. nginx and Apache talk to PHP-FPM;
    | OpenLiteSpeed cannot and runs LSPHP over LSAPI instead — different
    | packages, paths, ini files, and no per-version service at all. One
    | server runs one web server, so it has exactly one of these.
    */

    'php_stacks' => [
        'fpm' => ['driver' => FpmPhpStack::class],

        /*
        | LSPHP. Note `{compact}` — LiteSpeed names everything `lsphp84`, not
        | `lsphp8.4`, and assuming the dot produces a "package not found" that
        | reads like a broken repository. Unverified against a real box.
        */
        'lsphp' => [
            'driver' => LsphpPhpStack::class,
            'dir' => env('SERVER_LSWS_DIR', '/usr/local/lsws'),
            'ini_path' => '{root}/lsphp{compact}/etc/php/{version}/litespeed/php.ini',
            /*
            | Two binaries, and they are not interchangeable. `php` is the
            | ordinary CLI, used by installers; `lsphp` is the LSAPI build the
            | web server spawns. A vhost pointed at the CLI runs no PHP at all.
            |
            | Lists, not single paths, and probed in order: LSPHP is not always
            | in the lsws tree. Note the dot in the /usr/bin forms where the
            | lsws tree uses `lsphp82` — that asymmetry is real, and taken from
            | the Go agent that ran on production servers.
            */
            'binary_candidates' => [
                '{root}/lsphp{compact}/bin/php',
                '/usr/bin/lsphp{version}',
                '/usr/local/bin/lsphp{version}',
                '{root}/lsphp{compact}/bin/lsphp',
            ],
            'handler_candidates' => [
                '{root}/lsphp{compact}/bin/lsphp',
                '/usr/bin/lsphp{version}',
                '/usr/local/bin/lsphp{version}',
            ],
            'reload_command' => ['/usr/local/lsws/bin/lswsctrl', 'restart'],
            'sapis' => ['litespeed'],
            /*
            | Matches the FPM set deliberately. On OpenLiteSpeed the extension
            | toggles are refused — LSPHP has no phpenmod — so whatever ships
            | here is what the user is stuck with. A base set smaller than
            | FPM's would be a real difference in what the two can run.
            */
            'base_packages' => ['common', 'mysql', 'curl', 'mbstring', 'xml', 'zip', 'gd', 'intl', 'bcmath', 'soap'],
        ],
    ],

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

    /*
    | How many updates are waiting. This is the script the MOTD uses: it reads
    | the apt cache directly, so it needs no lock, no network and no `apt-get
    | update` — and it prints `updates;security` to **stderr**, not stdout.
    |
    | It ships in `update-notifier-common`, which a minimal install may not
    | have. Absent, the counts read `null` — deliberately not `0`, which would
    | claim the box is up to date on no evidence.
    */
    'apt_check' => env('SERVER_APT_CHECK', '/usr/lib/update-notifier/apt-check'),

    /*
    | Touched by APT::Periodic only when `apt-get update` *succeeded*, which is
    | the question being asked. The mtime of /var/lib/apt/lists moves on failed
    | runs too, so it would report a refresh that did not happen.
    */
    'apt_update_stamp' => env('SERVER_APT_UPDATE_STAMP', '/var/lib/apt/periodic/update-success-stamp'),

    /*
    | Its directory is root:adm 0750, so the panel user cannot open this
    | directly — it is read through ServerOps (sudo tail), not File::get.
    */
    'unattended_upgrades_log' => env('SERVER_UNATTENDED_LOG', '/var/log/unattended-upgrades/unattended-upgrades.log'),

    'redis_cli' => env('SERVER_REDIS_CLI', '/usr/bin/redis-cli'),

    /*
    |--------------------------------------------------------------------------
    | Runtimes
    |--------------------------------------------------------------------------
    |
    | Language versions the server can hold several of at once, shown under
    | Settings. Node is managed with fnm rather than nvm because a systemd
    | unit's ExecStart needs an absolute binary path and has no shell to
    | source a profile in — fnm keeps every version at a fixed, readable path.
    |
    | Installed system-wide so one copy serves every site user.
    |
    */

    // A plain scheduled reboot, separate from unattended-upgrades' reboot
    // after a required update.
    'reboot_schedule' => [
        'file' => env('SERVER_REBOOT_SCHEDULE_FILE', 'panel-reboot'),
        'default_hour' => (int) env('SERVER_REBOOT_SCHEDULE_HOUR', 3),
        // Not on the hour. Cron fires every :00 job on the same tick, and a
        // reboot landing on top of a backup is how you get a half-written
        // archive. ServerAvatar's docs advise the same buffer.
        'minute' => (int) env('SERVER_REBOOT_SCHEDULE_MINUTE', 10),
    ],

    'runtimes' => [
        'node' => [
            // Same idea as the PHP list above, matched against fnm's output.
            'failure_reasons' => [
                'package_not_found' => '/Can\'t find version|Version .* not found|unknown version/i',
                'network' => '/error sending request|dns error|Connection timed out|failed to lookup address/i',
                'no_space' => '/No space left on device/i',
            ],

            'binary' => env('SERVER_FNM_BINARY', '/usr/local/bin/fnm'),
            'dir' => env('SERVER_FNM_DIR', '/opt/fnm'),
            // Whatever Node was already on the box. Detected and reported so
            // a migrated server keeps working; never modified.
            'system_binary' => env('SERVER_NODE_BINARY', 'node'),
            // Newest patch of this many majors, so the picker is a list
            // somebody can read rather than every release ever made.
            'installable_majors' => (int) env('SERVER_NODE_INSTALLABLE_MAJORS', 6),
            'install_timeout' => (int) env('SERVER_NODE_INSTALL_TIMEOUT', 900),
        ],

        // How many site names a version carries in a list response. Enough
        // to answer "whose site breaks", never enough to bloat a payload the
        // screen loads on every visit. The count is always the true total.
        'pinned_sites_shown' => (int) env('SERVER_PINNED_SITES_SHOWN', 5),

        // Where support and end-of-life dates come from. Node publishes its
        // own schedule; PHP does not publish lifecycle JSON, so endoflife.date
        // is the practical source. Both are read by a scheduled command and
        // cached — never fetched inside a request.
        'lifecycle' => [
            'node_url' => env('RUNTIME_LIFECYCLE_NODE_URL', 'https://raw.githubusercontent.com/nodejs/Release/main/schedule.json'),
            'php_url' => env('RUNTIME_LIFECYCLE_PHP_URL', 'https://endoflife.date/api/php.json'),
        ],

        'php' => [
            /*
            | Why an install failed, matched against apt's output in order.
            |
            | These become `reason` codes on the PHP screen, rendered into a
            | sentence in the viewer's locale — the raw output is never shown,
            | because it names internal paths and cannot be translated. An
            | unmatched failure is `unknown`, which still carries a reference
            | to the server-ops log; guessing a cause we cannot recognise
            | would be worse than saying we do not know.
            */
            'failure_reasons' => [
                'package_not_found' => '/Unable to locate package|has no installation candidate/i',
                'apt_lock' => '/Could not get lock|Unable to acquire the dpkg frontend lock/i',
                'network' => '/Temporary failure resolving|Failed to fetch|Connection timed out/i',
                'no_space' => '/No space left on device/i',
            ],

            // A bare phpX.Y-fpm has no mysql, no curl, no mbstring — every
            // application in the marketplace would fail on it. Installing a
            // version means installing something usable.
            'base_packages' => array_values(array_filter(explode(',', (string) env(
                'SERVER_PHP_BASE_PACKAGES',
                'fpm,cli,common,mysql,curl,mbstring,xml,zip,gd,intl,bcmath,soap'
            )))),
            'install_timeout' => (int) env('SERVER_PHP_INSTALL_TIMEOUT', 900),

            // SAPIs an extension is toggled in. All of them together: cli and
            // fpm diverging means a site that works in a browser and fails in
            // a cron deploy, with nothing on screen explaining why.
            'sapis' => array_values(array_filter(explode(',', (string) env('SERVER_PHP_SAPIS', 'cli,fpm')))),

            // Share the phpX.Y- prefix but are not extensions: the SAPIs
            // themselves, the shared config package, the headers. Nothing to
            // toggle, so nothing to list.
            'non_extension_packages' => array_values(array_filter(explode(',', (string) env(
                'SERVER_PHP_NON_EXTENSION_PACKAGES',
                'cli,common,dev,fpm,cgi,phpdbg,embed,litespeed,all_dev'
            )))),

            // Modules the panel itself needs. Turning one of these off under
            // the panel's own version means the request to turn it back on
            // never gets answered.
            'panel_required' => array_values(array_filter(explode(',', (string) env(
                'SERVER_PHP_PANEL_REQUIRED',
                'curl,mbstring,xml,dom,tokenizer,fileinfo,pdo,pdo_sqlite,sqlite3,phar,openssl,simplexml,xmlwriter,ctype'
            )))),
        ],
    ],

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
            // `installer` is what makes an engine offerable in the setup page.
            // MongoDB is operable but not installable yet — it needs its own apt
            // repository — so it has none, and the catalog says so rather than
            // showing a button that cannot work.
            'mysql' => ['label' => 'MySQL', 'driver' => 'sql', 'client' => env('SERVER_MYSQL_CLIENT', 'mysql'), 'dump_client' => env('SERVER_MYSQLDUMP', 'mysqldump'), 'default_port' => 3306, 'default_socket' => '/var/run/mysqld/mysqld.sock', 'installer' => MySqlInstaller::class],
            'mariadb' => ['label' => 'MariaDB', 'driver' => 'sql', 'client' => env('SERVER_MARIADB_CLIENT', 'mariadb'), 'dump_client' => env('SERVER_MARIADBDUMP', 'mariadb-dump'), 'default_port' => 3306, 'default_socket' => '/var/run/mysqld/mysqld.sock', 'installer' => MariaDbInstaller::class],
            'mongodb' => ['label' => 'MongoDB', 'driver' => 'mongo', 'client' => env('SERVER_MONGO_CLIENT', 'mongosh'), 'dump_client' => env('SERVER_MONGODUMP', 'mongodump'), 'restore_client' => env('SERVER_MONGORESTORE', 'mongorestore'), 'default_port' => 27017, 'default_socket' => null, 'installer' => null],
        ],

        // Engine installs pull a few hundred MB and run their own post-install
        // scripts; 60s would kill one mid-configure.
        'install_timeout' => (int) env('SERVER_DB_INSTALL_TIMEOUT', 900),

        // apt output -> stable reason code. The human sentence is built from the
        // code at read time in the viewer's locale; stderr is never stored,
        // because its wording changes between releases and it leaks paths.
        'failure_reasons' => [
            'package_not_found' => '/Unable to locate package|has no installation candidate/i',
            'apt_lock' => '/Could not get lock|dpkg frontend lock|another process is using/i',
            'no_space' => '/No space left on device|not enough free disk space/i',
            'network' => '/Temporary failure resolving|Could not resolve|Connection failed|Unable to connect/i',
            'dpkg_broken' => '/dpkg was interrupted|broken packages|Sub-process .* returned an error/i',
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

    /*
    | Deploy-on-push. One verifier per provider, because the three sign
    | differently and one of the differences is a trap: GitHub's header is
    | `X-Hub-Signature-256` and Bitbucket's is `X-Hub-Signature` — the same
    | scheme under a name that differs by a suffix.
    |
    | `timestamp_tolerance` bounds replay on GitLab's signed deliveries, which
    | carry a timestamp inside the signed content. A captured delivery stays
    | validly signed forever; the window is what stops it being replayable
    | forever. Five minutes covers ordinary clock skew and queueing.
    |
    | `delivery_memory` is how long a delivery id is remembered so a retried or
    | replayed delivery does not deploy twice. Providers retry on timeout, so
    | this is a normal occurrence, not an attack.
    */
    'webhooks' => [
        'timestamp_tolerance' => (int) env('SERVER_WEBHOOK_TIMESTAMP_TOLERANCE', 300),
        'delivery_memory' => (int) env('SERVER_WEBHOOK_DELIVERY_MEMORY', 3600),

        'providers' => [
            'github' => ['driver' => GithubWebhook::class],
            'gitlab' => ['driver' => GitlabWebhook::class],
            'bitbucket' => ['driver' => BitbucketWebhook::class],
        ],
    ],

];
