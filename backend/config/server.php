<?php

use App\Services\Applications\Types\AdminerSiteType;
use App\Services\Applications\Types\AkauntingSiteType;
use App\Services\Applications\Types\CraftCmsSiteType;
use App\Services\Applications\Types\DockerSiteType;
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
use App\Services\Server\Applications\Installers\AdminerInstaller;
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
use App\Services\Server\Backups\Steps\ArchiveVolumes;
use App\Services\Server\Backups\Steps\CheckLocalSpace;
use App\Services\Server\Backups\Steps\DumpDatabase;
use App\Services\Server\Backups\Steps\PruneOldBackups;
use App\Services\Server\Backups\Steps\UploadArtifact;
use App\Services\Server\Backups\Steps\VerifyArtifact;
use App\Services\Server\Databases\Installers\MariaDbInstaller;
use App\Services\Server\Databases\Installers\MongoDbInstaller;
use App\Services\Server\Databases\Installers\MySqlInstaller;
use App\Services\Server\Databases\Installers\PostgresInstaller;
use App\Services\Server\DiskCleaner\Targets\AptCacheTarget;
use App\Services\Server\DiskCleaner\Targets\AptOrphansTarget;
use App\Services\Server\DiskCleaner\Targets\DockerImagesTarget;
use App\Services\Server\DiskCleaner\Targets\JournalTarget;
use App\Services\Server\DiskCleaner\Targets\RotatedLogsTarget;
use App\Services\Server\DiskCleaner\Targets\ServiceLogsTarget;
use App\Services\Server\DiskCleaner\Targets\SiteLogsTarget;
use App\Services\Server\DiskCleaner\Targets\TmpTarget;
use App\Services\Server\Doctor\Checks\AccountLocksCheck;
use App\Services\Server\Doctor\Checks\BinariesCheck;
use App\Services\Server\Doctor\Checks\DatabaseCheck;
use App\Services\Server\Doctor\Checks\DockerCheck;
use App\Services\Server\Doctor\Checks\DockerExposureCheck;
use App\Services\Server\Doctor\Checks\DriverContentionCheck;
use App\Services\Server\Doctor\Checks\DynamicResponseLimitCheck;
use App\Services\Server\Doctor\Checks\FrontendBuildCheck;
use App\Services\Server\Doctor\Checks\HealthEndpointCheck;
use App\Services\Server\Doctor\Checks\HomeAccessCheck;
use App\Services\Server\Doctor\Checks\PhpIsolationCheck;
use App\Services\Server\Doctor\Checks\PrivilegeCheck;
use App\Services\Server\Doctor\Checks\QueueCheck;
use App\Services\Server\Doctor\Checks\ServicesCheck;
use App\Services\Server\Doctor\Checks\SiteRootLockCheck;
use App\Services\Server\Doctor\Checks\WebServerCheck;
use App\Services\Server\Doctor\Checks\WritablePathsCheck;
use App\Services\Server\Php\Stacks\FpmPhpStack;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\Restores\Steps\DownloadArtifact;
use App\Services\Server\Restores\Steps\ExtractArchive;
use App\Services\Server\Restores\Steps\RestartProcess;
use App\Services\Server\Restores\Steps\RestoreDatabase;
use App\Services\Server\Restores\Steps\RestoreVolumes;
use App\Services\Server\Restores\Steps\SafetyBackup;
use App\Services\Server\Restores\Steps\SwapFiles;
use App\Services\Server\Restores\Steps\VerifyDownload;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;

return [

    /*
     * How long a read-only screen that asks the server (Settings, dashboard facts, database engine
     * list) keeps its answer. Panel changes clear it at once; this only bounds how long a manual
     * change on the box takes to show. 0 turns it off.
     */
    'probe_cache_seconds' => (int) env('SERVER_PROBE_CACHE_SECONDS', 30),

    /*
     * Wildcard-DNS services behind the temporary hostname a new site gets before its real domain
     * points here. Both resolve `<anything>.<ip>.<suffix>` (dotted or dashed IP).
     *
     * One list for both offering and recognising: a suffix offered but not recognised would give
     * hostnames the panel treats as the user's own and sends to Let's Encrypt, burning its shared
     * weekly quota. More than one so the panel does not depend on a single free service.
     */
    'temporary_domain_suffixes' => ['nip.io', 'sslip.io'],

    /*
    |--------------------------------------------------------------------------
    | Privilege escalation
    |--------------------------------------------------------------------------
    |
    | php-fpm runs as the unprivileged panel account, so anything touching the system goes through
    | sudo. install.sh grants exactly the binaries below NOPASSWD in /etc/sudoers.d/<slug>; a binary
    | listed here but not granted fails at runtime, and one granted but not listed is a needless
    | privilege.
    |
    | The prefix is added in ServerOps, in one place. Commands stay arrays (no shell, no
    | interpolation). `sudo` is off under phpunit (see phpunit.xml) so tests assert on the real
    | commands; ServerOpsPrivilegeTest covers the prefixing.
    |
    */

    'privilege' => [
        'sudo' => (bool) env('SERVER_OPS_SUDO', true),

        // Binaries that do not work as the panel user.
        //
        // The ONE list: install.sh renders the sudoers file from here via
        // `artisan panel:sudoers --print`, and so does every update. Two hand-kept lists drifted and
        // broke features. A binary not in /usr/bin also goes in `paths` below.
        'binaries' => [
            'apt-get', 'apt-cache', 'dpkg-query',
            // Runs unattended-upgrades' own binary (not `apt-get upgrade`) so "install now" applies the
            // policy the toggle configured, even when apt's timer is disabled.
            'unattended-upgrade',
            // Runs the panel update in a transient unit so restarting panel services mid-update does
            // not kill it (see PanelUpdateRunner).
            'systemctl', 'journalctl', 'systemd-run',
            'useradd', 'userdel', 'usermod', 'groupadd',
            'chpasswd', 'gpasswd', 'getent', 'id',
            'tee', 'touch', 'mkdir', 'chown', 'chmod', 'rm',
            'cp', 'mv', 'ln', 'install', 'truncate',
            // The file manager reads the first bytes of a site file (owned by the site user) to
            // decide what a preview may claim it is.
            'find', 'tail', 'head', 'cat', 'test', 'which', 'stat',
            // Immutable flag on a site root (SiteRootLock): stops the site user renaming their
            // root-owned site directory away and substituting their own.
            'chattr', 'lsattr',
            // Per-home ACLs (HomeDirectoryAccess): a home is closed to other local accounts and
            // opened, by user id, to the panel and the web server only.
            'setfacl', 'getfacl',
            // `openssl req` writes into /etc/ssl and `openssl x509 -enddate` reads
            // /etc/letsencrypt/live, both root-only.
            'openssl',
            // `crontab -l -u <user>` reads another user's crontab during a server sync.
            'crontab',
            // certbot writes /etc/letsencrypt and reads the account key.
            'certbot',
            'runuser', 'sh', 'env', 'rsync',
            // `openlitespeed` beside `lswsctrl`: lswsctrl restarts, only `openlitespeed -t` validates
            // a config.
            'nginx', 'apachectl', 'lswsctrl', 'openlitespeed',
            // Workers run as supervisord programs; this writes and controls them.
            'supervisorctl',
            'phpenmod', 'phpdismod', 'update-alternatives',
            // DB dump/restore clients (backups run `dump_client` from the engines list below).
            //
            // Not `redis-cli`: it reaches Redis over TCP and needs no root, and elevating it put the
            // Redis password on sudo's command line, which sudo logs to auth.log and the journal.
            'mysql', 'mysqldump', 'mariadb', 'mariadb-dump',
            'mongosh', 'mongodump', 'mongorestore',
            // psql is a pg_wrapper symlink; sudo matches by the path given, as with /usr/bin/mysql.
            'psql', 'pg_dump', 'pg_restore', 'pg_isready', 'pg_lsclusters',
            'ufw', 'fail2ban-client', 'sshd',
            // Container login installs its own sudoers drop-in, moved into place only after
            // `visudo -cqf` accepts it (the rule PanelSudoers follows).
            'visudo',
            'fallocate', 'mkswap', 'swapon', 'swapoff',
            'hostnamectl', 'timedatectl', 'shutdown', 'df', 'du',
            'ps', 'kill', 'ss', 'curl', 'unzip', 'zip',
            'tar', 'git', 'fnm', 'wp',
            // Paid addons Central installs (sv-wp-toolkit, log-monitoring-suite). wp-toolkit sudoes to
            // the site user and writes web server rules; log-monitoring-suite reads every site's
            // access log. Called only from the central-only addon endpoints.
            'wp-toolkit', 'log-monitoring-suite',
            // Docker is granted to the panel, not via the `docker` group: group membership is root
            // equivalence (bind-mount / into a container), so only specific commands are elevated.
            'docker',
        ],

        // Where a binary lives, when it is not /usr/bin/<name>. Only the exceptions.
        //
        // sudo matches the *resolved* absolute path, so a wrong entry silently denies the command
        // with "a password is required".
        //
        // runuser lists both spellings: /usr/sbin on Debian/Ubuntu, /usr/bin on RHEL-family; sudo
        // ignores entries whose file does not exist.
        'paths' => [
            'useradd' => ['/usr/sbin/useradd'],
            'userdel' => ['/usr/sbin/userdel'],
            'usermod' => ['/usr/sbin/usermod'],
            'groupadd' => ['/usr/sbin/groupadd'],
            'chpasswd' => ['/usr/sbin/chpasswd'],
            'runuser' => ['/usr/sbin/runuser', '/usr/bin/runuser'],
            'nginx' => ['/usr/sbin/nginx'],
            'apachectl' => ['/usr/sbin/apachectl'],
            // OpenLiteSpeed installs under /usr/local/lsws, not /usr/bin.
            'lswsctrl' => ['/usr/local/lsws/bin/lswsctrl'],
            'openlitespeed' => ['/usr/local/lsws/bin/openlitespeed'],
            'phpenmod' => ['/usr/sbin/phpenmod'],
            'phpdismod' => ['/usr/sbin/phpdismod'],
            'ufw' => ['/usr/sbin/ufw'],
            'sshd' => ['/usr/sbin/sshd'],
            'visudo' => ['/usr/sbin/visudo'],
            'mkswap' => ['/usr/sbin/mkswap'],
            'swapon' => ['/usr/sbin/swapon'],
            'swapoff' => ['/usr/sbin/swapoff'],
            'shutdown' => ['/usr/sbin/shutdown'],
            'fnm' => ['/usr/local/bin/fnm'],
            'wp' => ['/usr/local/bin/wp'],
            'wp-toolkit' => ['/usr/local/bin/wp-toolkit'],
            'log-monitoring-suite' => ['/usr/local/bin/log-monitoring-suite'],
        ],

        // Granted as patterns, not names. PoolManager tests a per-app pool with `php-fpmX.Y -t`, and
        // installed versions change through the panel; ServerOps::elevate() mirrors this with its
        // str_starts_with('php-fpm') check.
        'wildcards' => [
            '/usr/sbin/php-fpm*',
        ],

        // The file the grant is written to. install.sh names it from PANEL_SLUG, which also defaults
        // the panel's account name; override when the two differ.
        'sudoers_file' => env('PANEL_SUDOERS_FILE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File manager archives
    |--------------------------------------------------------------------------
    |
    | Compress and extract run on the queue, not in the request: the installed web servers time out
    | at different points (nginx 300s, Apache 300s, OpenLiteSpeed 60s), and past the lowest the
    | request dies above PHP, where cleanup of a half-written archive cannot run.
    |
    */

    'files' => [
        // Own keys, not the backups group's: a six-hour nightly job and a button someone is waiting
        // on want different trade-offs.
        'compressor' => env('FILES_COMPRESSOR', 'auto'),
        'compression_level' => (int) env('FILES_COMPRESSION_LEVEL', 1),

        // Ceiling for the queued archive job. No size limit: a cap only moves the wall.
        //
        // Kept in step with `retry_after` by QueueTimeoutTest: a job must not outlive its own
        // reservation.
        'archive_job_timeout' => (int) env('FILES_ARCHIVE_JOB_TIMEOUT', 21600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Ordered steps of one backup run. Prune is last and runs only after verify, so the panel never
    | deletes an old backup to make room for one that then fails.
    */

    'backups' => [
        'steps' => [
            // Only for a "This server" destination: is the folder there and will the backup fit?
            // First, so a refusal costs nothing.
            CheckLocalSpace::class,
            DumpDatabase::class,
            // Before ArchiveFiles, which folds the per-volume tars written here into the uploaded
            // artefact.
            ArchiveVolumes::class,
            ArchiveFiles::class,
            UploadArtifact::class,
            VerifyArtifact::class,
            PruneOldBackups::class,
        ],

        // Restore stages, in order. Everything before `restore_database` is non-destructive: a
        // failure leaves the live application untouched.
        'restore_steps' => [
            DownloadArtifact::class,
            VerifyDownload::class,
            SafetyBackup::class,
            ExtractArchive::class,
            RestoreDatabase::class,
            // After extraction, before the compose file is swapped in: stops containers and replaces
            // volume contents; RestartProcess starts them, so the app comes up on the restored
            // definition.
            RestoreVolumes::class,
            SwapFiles::class,
            RestartProcess::class,
        ],

        // Dumps and archives are written here before upload. Under storage/, not /tmp, which is
        // cleared on reboot and often a small tmpfs.
        'working_dir' => env('BACKUP_WORKING_DIR', storage_path('app/backups')),

        // "This server" destinations. The folder must sit below one of these roots. A backup is
        // refused when it would leave less than min_free_percent or min_free_bytes (whichever is
        // more) free: a full disk takes every site down.
        'local' => [
            'allowed_roots' => ['/var/backups', '/mnt', '/media'],
            'default_path' => '/var/backups/panel',
            'min_free_percent' => (float) env('BACKUP_LOCAL_MIN_FREE_PERCENT', 10),
            'min_free_bytes' => (int) env('BACKUP_LOCAL_MIN_FREE_BYTES', 2 * 1024 * 1024 * 1024),
        ],

        /*
         * Downloads of backups kept on SFTP or FTP (prepare, then download).
         *
         * Those providers have no browser-followable link, so the panel copies the archive here
         * (under storage/, like `working_dir`) and serves the copy. Copies live
         * `download_ttl_seconds`, then `backups:prune-downloads` removes them.
         *
         * `download_free_margin_bytes` must stay free after the copy lands. `download_concurrency`
         * caps simultaneous copies; each holds a PHP worker for the whole transfer.
         */
        'download_dir' => env('BACKUP_DOWNLOAD_DIR', storage_path('app/backup-downloads')),
        'download_ttl_seconds' => (int) env('BACKUP_DOWNLOAD_TTL_SECONDS', 3600),
        'download_free_margin_bytes' => (int) env('BACKUP_DOWNLOAD_FREE_MARGIN_BYTES', 1024 * 1024 * 1024),
        'download_concurrency' => (int) env('BACKUP_DOWNLOAD_CONCURRENCY', 2),

        /*
         * How long one backup or restore may run before the worker kills it.
         *
         * Six hours is the point past which waiting is worse than failing; the stall guard below,
         * not this number, ends a wedged run. Raising one without the other lets a dead upload hold
         * the only worker until the timeout.
         *
         * `queue.connections.redis.retry_after` MUST stay above this, or a second worker takes a
         * slow job and archives the site twice to one key. `config/queue.php` derives it from here.
         */
        'job_timeout' => (int) env('BACKUP_JOB_TIMEOUT', 21600),

        /*
         * Fail an upload that has transferred nothing for this long.
         *
         * A wall-clock limit cannot tell a slow link from a dead one; bytes can. Google drops idle
         * resumable sessions and the client may not notice, leaving the worker blocked on a
         * half-closed socket until `job_timeout`. Twenty minutes because a 100 MB chunk on a
         * throttled link takes most of a minute, while a stalled one shows zero movement.
         */
        'upload_stall_seconds' => (int) env('BACKUP_UPLOAD_STALL_SECONDS', 1200),

        /*
         * How long a run that has reported no progress at all is given.
         *
         * Only reachable before the upload starts (crash during dump or archive: no heartbeat).
         * Separate from `job_timeout` so raising that does not stretch this. Must stay above the
         * longest archive step, or the guard frees while the worker still writes and a second
         * backup starts alongside.
         */
        'no_heartbeat_stale_seconds' => (int) env('BACKUP_NO_HEARTBEAT_STALE_SECONDS', 3900),

        /*
         * Memory the upload step is allowed while it runs.
         *
         * `GoogleDriveAdapter::upload()` streams a resumable upload above 100 MB (flat memory), but
         * at or below it a one-shot `MediaFileUpload` base64-encodes the whole archive in RAM
         * (worst case about 500 MB). So small Drive backups OOM under the 128M default and large
         * ones do not. A ceiling is not a reservation; PHP allocates only what it uses.
         *
         * Forcing the streaming path means overriding the vendor's `upload()`, which reads private
         * members and calls a method that does not exist; not worth it.
         */
        'upload_memory_limit' => env('BACKUP_UPLOAD_MEMORY_LIMIT', '768M'),

        /*
         * What compresses the archive, and how hard.
         *
         * `pigz` is gzip split across cores: same format, readable by plain `gzip -d`. `auto` falls
         * back to gzip when pigz is absent, because the updater ships code, never packages, and
         * requiring pigz would break existing panels.
         *
         * Level 1, not gzip's 6: already-compressed data gains nothing, and compressible data is
         * 1.8x faster for 7.8% more bytes. A backup is written once and rarely read.
         */
        /*
         * How many *consecutive* failed chunks end an upload.
         *
         * Consecutive, not total: the counter resets whenever a chunk lands, so a long upload that
         * recovered from every failure is not killed. Backoff grows so a blip does not become a
         * ban.
         */
        'upload_attempts' => (int) env('BACKUP_UPLOAD_ATTEMPTS', 5),
        'upload_backoff' => [5, 15, 30, 60],

        'compressor' => env('BACKUP_COMPRESSOR', 'auto'),
        'compression_level' => (int) env('BACKUP_COMPRESSION_LEVEL', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage destinations
    |--------------------------------------------------------------------------
    |
    | `panel_url` is the panel's own public origin. It is configuration, not derived from the
    | request, because Google OAuth requires the registered redirect URI to match the token
    | exchange byte for byte, and a request-derived origin varies with proxies and Host headers.
    |
    */

    'storage' => [
        'panel_url' => env('FRONTEND_URL', env('APP_URL', '')),

        // Bounds a hung connect so it does not hold an HTTP worker.
        'oauth_timeout_seconds' => (int) env('STORAGE_OAUTH_TIMEOUT_SECONDS', 15),

        // How long an operator has to finish approving at Google before the callback is refused;
        // short enough that an abandoned attempt cannot be resumed later by someone else.
        'oauth_state_ttl_seconds' => (int) env('STORAGE_OAUTH_STATE_TTL_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-check (doctor)
    |--------------------------------------------------------------------------
    |
    | Checks run by `panel:doctor` and the admin self-check screen, in display order: cheapest and
    | most fundamental first (if privilege fails, most of the rest is noise).
    |
    */

    'doctor' => [
        'checks' => [
            PrivilegeCheck::class,
            BinariesCheck::class,
            AccountLocksCheck::class,
            ServicesCheck::class,
            WebServerCheck::class,
            DynamicResponseLimitCheck::class,
            DockerCheck::class,
            DockerExposureCheck::class,
            FrontendBuildCheck::class,
            WritablePathsCheck::class,
            DatabaseCheck::class,
            QueueCheck::class,
            DriverContentionCheck::class,
            PhpIsolationCheck::class,
            SiteRootLockCheck::class,
            HomeAccessCheck::class,
            HealthEndpointCheck::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transient failures
    |--------------------------------------------------------------------------
    |
    | Some failures mean "busy, nothing happened" (the /etc/passwd lock, the dpkg lock held by
    | unattended-upgrades). Retrying is safe only because the command never started, so only
    | patterns with that guarantee belong here; a failure that might have half-completed must not
    | be retried.
    |
    */

    /*
    | apt's own lock budget, separate from `transient`.
    |
    | The generic retry suits a passwd lock (seconds); apt's holder on a fresh server is
    | `unattended-upgrades`, which runs for minutes. 40 x 15s covers a ten-minute first boot, and
    | costs nothing when the lock is free.
    */
    'apt' => [
        'lock_attempts' => (int) env('SERVER_APT_LOCK_ATTEMPTS', 40),
        'lock_delay_ms' => (int) env('SERVER_APT_LOCK_DELAY_MS', 15000),
    ],

    'transient' => [
        'attempts' => (int) env('SERVER_OPS_RETRIES', 3),
        'delay_ms' => (int) env('SERVER_OPS_RETRY_DELAY_MS', 1500),

        // A lock that survives every retry means a dead holder, so "try again" can never come true;
        // these get their own code and message. `panel:doctor` names the files.
        'stale_lock_patterns' => [
            'cannot lock',
        ],

        // Matched case-insensitively against stderr.
        'patterns' => [
            // useradd/usermod/userdel/groupadd: another account tool holds the passwd/group lock.
            'cannot lock',
            'try again later',
            // apt/dpkg.
            'could not get lock',
            'unable to acquire the dpkg frontend lock',
            'is another process using it',
            // Generic EAGAIN from a lock file.
            'resource temporarily unavailable',
            // certbot takes its lock before doing anything (often its own renew timer), so nothing
            // was started.
            'another instance of certbot is already running',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account operation serialization
    |--------------------------------------------------------------------------
    |
    | One shared app-level lock runs every account command (useradd, userdel, usermod, gpasswd,
    | chpasswd, passwd) strictly one at a time; the OS lock on /etc/passwd otherwise fails
    | concurrent ones with "cannot lock /etc/passwd".
    |
    | ttl:  hold ceiling if the holder dies mid-command. MUST exceed the slowest account command
    |       including ServerOps' transient retries (command timeout x attempts), or the lock
    |       expires mid-command. Raise it if you raise SERVER_OPS_RETRIES a lot.
    | wait: how long a queued command waits before returning 503 "busy, try again".
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
    | Parent directory for system users' home directories (e.g. /home/<username>). Overridable so
    | tests can use a temp path.
    | temp path.
    |
    */

    'home_base' => env('SERVER_HOME_BASE', '/home'),

    // The Linux account the panel itself runs as (PHP-FPM pool and queue worker). Empty: the
    // account this process runs as. HomeDirectoryAccess lets exactly this account through a closed
    // home.
    'panel_account' => env('SERVER_PANEL_ACCOUNT', ''),

    // One shared "site unavailable" page served in place of a disabled app's vhost; a single
    // directory works for every serving profile, including node/proxy sites with no document root.
    'disabled_page_root' => env('SERVER_DISABLED_PAGE_ROOT', '/var/www/.disabled-site'),

    /*
    |--------------------------------------------------------------------------
    | 8G Firewall
    |--------------------------------------------------------------------------
    |
    | nginx's `map` directive only works in the `http` context, so the six rule-category maps live
    | in one server-wide file (see App\Services\Server\Applications\Waf8GManager). Apache's
    | SetEnvIfExpr is per-vhost, so its ruleset is `Include`d from resources/waf/ only for sites
    | that turn it on.
    */
    'waf' => [
        'nginx_maps_path' => env('SERVER_WAF_NGINX_MAPS_PATH', '/etc/nginx/conf.d/00-8g-firewall-maps.conf'),
        // Apache's SetEnvIf* directives are per-vhost, so the path is free; one shared file avoids
        // duplicating ~15KB of regex into every vhost, and each site `Include`s it (as the official
        // 8G Apache port does).
        'apache_setenvif_path' => env('SERVER_WAF_APACHE_SETENVIF_PATH', '/etc/apache2/waf/8g-apache-setenvif.conf'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cron.d directory
    |--------------------------------------------------------------------------
    |
    | Directory where managed cron job files are written (one per job). Overridable for tests.
    |
    */

    'cron_d' => env('SERVER_CRON_D', '/etc/cron.d'),

    // The systemd unit of the cron daemon that reads cron_d. The Cron Jobs
    // list reports whether it is running.
    'cron_unit' => env('SERVER_CRON_UNIT', 'cron'),

    /*
    |--------------------------------------------------------------------------
    | Per-site web server rules
    |--------------------------------------------------------------------------
    |
    | One root-owned directory per site, `{root}/{slug}`, whose `*.conf` files the site's vhost
    | includes. The vhost is re-rendered on every domain, certificate or HTTPS change and loses
    | hand edits; this directory is never rendered. The panel only creates it and removes it with
    | the site; its contents belong to whoever put them there (today the WordPress toolkit's
    | xmlrpc and uploads-PHP blocks).
    |
    | Root-owned and outside the site's home on purpose: otherwise the site's own account could
    | write web server configuration.
    |
    */

    'site_rules_root' => env('SERVER_SITE_RULES_ROOT', '/etc/panel-site-rules'),

    /*
    |--------------------------------------------------------------------------
    | Addon command-line tools
    |--------------------------------------------------------------------------
    |
    | Paid addons are separate binaries Central installs on the server; the
    | Paid addons are separate binaries Central installs on the server; the panel only detects and
    | runs them for Central (routes/api/server/central-addons.php). Whether a server may use one
    | is decided by the binary against the vendor's licence API, never here. An absent binary is
    | "not installed".
    |
    | Log Monitoring Suite reads its database location from a `.env` in its working directory, so
    | it always starts from `workdir`; from the panel's directory it would read the panel's .env.
    |
    */

    'addons' => [
        'wp_toolkit' => [
            'binary' => env('ADDON_WP_TOOLKIT_BINARY', '/usr/local/bin/wp-toolkit'),
        ],
        'log_monitoring_suite' => [
            'binary' => env('ADDON_LOG_MONITORING_SUITE_BINARY', '/usr/local/bin/log-monitoring-suite'),
            // 0755, not 0700: the panel chdirs here as its own account before sudo. The .env inside is
            // 0600.
            'workdir' => env('ADDON_LOG_MONITORING_SUITE_WORKDIR', '/etc/log-monitoring-suite'),
            // Its SQLite database, root-only (0700).
            'data_dir' => env('ADDON_LOG_MONITORING_SUITE_DATA_DIR', '/var/lib/log-monitoring-suite'),
            // The fetch and prune timers (its docs/INTEGRATION.md §7a).
            'units_dir' => env('ADDON_LOG_MONITORING_SUITE_UNITS_DIR', '/etc/systemd/system'),
            // `logs:fetch` refuses to run with less free than this on the database's filesystem.
            'min_free_bytes' => 5 * 1024 * 1024 * 1024,
            'retention_days' => [7, 14, 30],
            'default_retention_days' => 7,
            // v7's insighthub-agent ingests the same access logs, so it is removed (data included)
            // before this one is installed.
            'v7' => [
                'service' => 'insighthub.service',
                'unit_files' => ['/lib/systemd/system/insighthub.service', '/etc/systemd/system/insighthub.service'],
                'directories' => ['/insighthub', '/var/log/insighthub'],
            ],
            // Reports start the binary and open its database; the same chart asked for twice within
            // this window is answered once.
            'cache_seconds' => (int) env('ADDON_LOG_MONITORING_SUITE_CACHE_SECONDS', 300),
        ],
        // A request waits this long for a command; anything that can take
        // longer is queued and polled instead (AddonRun).
        'sync_timeout' => 55,
        'async_timeout' => 1800,

        // Installing and removing the binaries (POST/DELETE /central/addons/{addon}).
        //
        // Central sends a URL, a SHA-256 and an Ed25519 signature over the
        // file's bytes. The URL must be on Central's own host; the signature
        // is checked against this key (base64, 32 bytes). No key, no install:
        // a checksum alone only proves the file is the one the request named.
        'signing_public_key' => env('ADDON_SIGNING_PUBLIC_KEY'),
        // Test mode while Central has no signing key yet: off, an install
        // needs no key and no signature, but a signature that is sent is still
        // checked against a key that is set, and the checksum always is.
        // Must be true before launch.
        'require_signature' => (bool) env('ADDON_REQUIRE_SIGNATURE', false),
        // `uname -m` of this server, read when unset. For tests.
        'host_machine' => env('ADDON_HOST_MACHINE'),
        'download_max_bytes' => (int) env('ADDON_DOWNLOAD_MAX_BYTES', 209715200),
        'install_timeout' => 1800,
        // An install or remove still queued or running after this long is
        // taken to be stranded (a worker that died with it) and no longer
        // holds the next one off.
        'lifecycle_stranded_seconds' => 10800,
    ],

    /*
    | Cron mails job output, and a server with no MTA discards it silently, so managed jobs
    | redirect into one file per job here. Unbounded capture slowly fills the disk, so the
    | logrotate drop-in below is written with the first job and is not optional.
    */

    'cronjob_log_dir' => env('SERVER_CRONJOB_LOG_DIR', '/var/log/cronjobs'),

    /*
    | Where systemd records a pending shutdown: present while a restart is scheduled, and its USEC
    | key is when.
    */
    'reboot' => [
        'scheduled_file' => env('SERVER_REBOOT_SCHEDULED_FILE', '/run/systemd/shutdown/scheduled'),
    ],
    'cronjob_logrotate_file' => env('SERVER_CRONJOB_LOGROTATE', '/etc/logrotate.d/panel-cronjobs'),
    'cronjob_log_keep_days' => (int) env('SERVER_CRONJOB_LOG_KEEP_DAYS', 14),
    'cronjob_log_max_size' => env('SERVER_CRONJOB_LOG_MAX_SIZE', '10M'),

    /*
    | Run history is read out of the end of each job's log (CronjobHistory):
    | this much of it for a job's history, this much per job for the list's
    | "last run", at most this many runs, and at most this much output each.
    | A Run now is stopped after the last value, or a hung one would refuse
    | every later Run now.
    */
    'cronjob_history_window_bytes' => 1048576,
    'cronjob_latest_window_bytes' => 16384,
    'cronjob_history_runs' => 20,
    'cronjob_run_output_bytes' => 65536,
    'cronjob_run_now_max_seconds' => (int) env('SERVER_CRONJOB_RUN_NOW_MAX_SECONDS', 86400),

    /*
    |--------------------------------------------------------------------------
    | Server timezone sources
    |--------------------------------------------------------------------------
    |
    | Cron interprets schedules in the OS timezone, so "next run" is computed there. Read from the
    | filesystem, not `timedatectl`: this runs once per list row and a process per row is
    | expensive. Overridable for tests.
    |
    */

    'timezone_file' => env('SERVER_TIMEZONE_FILE', '/etc/timezone'),
    'localtime_link' => env('SERVER_LOCALTIME_LINK', '/etc/localtime'),

    /*
    |--------------------------------------------------------------------------
    | Server capability detection
    |--------------------------------------------------------------------------
    |
    | Fallback probes for when the installation script never ran (a server migrated from another
    | panel). Only one web server can own :80, so the first directory that exists wins.
    | Overridable for tests.
    |
    | Each entry is the directory the panel writes that web server's vhosts into, NOT the top of
    | its config tree: `/etc/apache2` is shipped by `phpX.Y-fpm`, so it exists on boxes that never
    | had Apache, and detecting on it made every OpenLiteSpeed server answer "apache".
    | `sites-available`, `conf.d` and `conf/vhosts` are shipped only by the web server's own
    | package.
    |
    */

    'web_servers' => [
        // Narrow FIRST, broad second: the first path that exists wins.
        //
        // Only Apache needs the narrow form: `/etc/apache2` is shipped by `phpX.Y-fpm` and exists
        // without Apache, while `sites-available` only the apache2 package brings. Nothing ships a
        // decoy `/etc/nginx` or `/usr/local/lsws`, and narrowing those could answer "no web server
        // found" on a server plainly running one.
        'nginx' => ['/etc/nginx/sites-available', '/etc/nginx'],
        'apache' => ['/etc/apache2/sites-available', '/etc/httpd/conf.d'],
        'openlitespeed' => ['/usr/local/lsws/conf/vhosts', '/usr/local/lsws'],
    ],

    'node_binary' => env('SERVER_NODE_BINARY', 'node'),

    // Where capability detection looks for a binary before asking `which`: sudo's default
    // secure_path. Colon-separated, like PATH; tests empty it so the host cannot answer for the
    // faked server.
    'binary_search_path' => array_values(array_filter(explode(':', (string) env(
        'SERVER_BINARY_SEARCH_PATH',
        '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin',
    )))),

    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    |
    | The site-type catalog. Each class declares its own fields, so the create form is rendered
    | from data and a new type costs one class and one line here.
    |
    */

    /*
    | Web servers the panel can write config for. One not listed is refused rather than guessed
    | at: an invented config would at best fail its own test and at worst take every site down.
    */

    'web_server_drivers' => [
        'nginx' => [
            'driver' => NginxDriver::class,
            // Every driver carries a label, used in the "not available on this web server" message.
            'label' => 'Nginx',
            // Read/merge only when nginx diagnoses an undersized hostname hash.
            'config_path' => env('SERVER_NGINX_CONFIG_PATH', '/etc/nginx/nginx.conf'),
            // The symlink nginx actually reads via its sites-enabled/* include.
            'sites_dir' => env('SERVER_NGINX_SITES_DIR', '/etc/nginx/sites-enabled'),
            // Where the real file is written; sites_dir then symlinks to it.
            'sites_available_dir' => env('SERVER_NGINX_SITES_AVAILABLE_DIR', '/etc/nginx/sites-available'),
            // Where the vhost templates point `access_log` / `error_log`.
            'log_dir' => env('SERVER_NGINX_LOG_DIR', '/var/log/nginx'),
            // The answer for names no site claims, under v7's names: port 80 in Ubuntu's own `default`
            // (written only while it is the package's untouched file or the panel's), 443 beside it.
            'catch_all' => env('SERVER_NGINX_CATCH_ALL', '/etc/nginx/sites-available/default'),
            'catch_all_ssl' => env('SERVER_NGINX_CATCH_ALL_SSL', '/etc/nginx/sites-available/000-default-ssl.conf'),
            'catch_all_enabled_dir' => env('SERVER_NGINX_CATCH_ALL_ENABLED_DIR', '/etc/nginx/sites-enabled'),
            'catch_all_package' => 'nginx-common',
            // Where it lived before v7's names; removed in the same change.
            'catch_all_legacy' => ['/etc/nginx/conf.d/panel-default-server.conf'],
            // The visitor's own address behind Cloudflare, and compression of more than HTML; both
            // http-level (CloudflareRealIp, NginxCompression).
            'real_ip' => env('SERVER_NGINX_REAL_IP', '/etc/nginx/conf.d/remoteip.conf'),
            'compression' => env('SERVER_NGINX_COMPRESSION', '/etc/nginx/conf.d/panel-gzip.conf'),
            // Which PHP stack this web server implies — see `php_stacks`.
            'php_stack' => 'fpm',
        ],
        'apache' => [
            'driver' => ApacheDriver::class,
            'label' => 'Apache',
            'sites_dir' => env('SERVER_APACHE_SITES_DIR', '/etc/apache2/sites-enabled'),
            'sites_available_dir' => env('SERVER_APACHE_SITES_AVAILABLE_DIR', '/etc/apache2/sites-available'),
            // `${APACHE_LOG_DIR}` in the templates, resolved: Apache expands it from envvars at start.
            'log_dir' => env('SERVER_APACHE_LOG_DIR', '/var/log/apache2'),
            // The answer for names no site claims, under v7's names: 000-default.conf (written only
            // while it is the package's untouched file or the panel's) and 000-default-ssl.conf, first
            // in sites-enabled. Apache takes the first vhost it loads.
            'catch_all' => env('SERVER_APACHE_CATCH_ALL', '/etc/apache2/sites-available/000-default.conf'),
            'catch_all_ssl' => env('SERVER_APACHE_CATCH_ALL_SSL', '/etc/apache2/sites-available/000-default-ssl.conf'),
            'catch_all_enabled_dir' => env('SERVER_APACHE_CATCH_ALL_ENABLED_DIR', '/etc/apache2/sites-enabled'),
            'catch_all_package' => 'apache2',
            'catch_all_legacy' => ['/etc/apache2/conf-available/panel-default-site.conf', '/etc/apache2/conf-enabled/panel-default-site.conf'],
            // The visitor's own address behind Cloudflare (CloudflareRealIp), in conf-enabled as v7.
            'real_ip' => env('SERVER_APACHE_REAL_IP', '/etc/apache2/conf-enabled/remoteip.conf'),
            'php_stack' => 'fpm',
        ],

        /*
        | OpenLiteSpeed. A site is a directory under `vhost_root` *and* two entries in
        | `shared_config`; see OlsSharedConfig for why that file is edited by marked region.
        |
        | Two paths are assumptions, and both are load-bearing:
        |
        |  - `listener`: the shipped config writes `listener Default{` with no space before the
        |    brace; insertInListener()'s `\s*` tolerates that. Do not "tidy" it.
        |  - `ssl_listener`: a fresh install has NO Defaultssl listener, only Default on *:8088
        |    (the case OlsListenerNotFoundException exists for; registration skips SSL maps).
        |
        | They are config so a wrong one is an edit rather than a patch.
        */
        'openlitespeed' => [
            'driver' => OlsDriver::class,
            'label' => 'OpenLiteSpeed',
            /*
            | No `site_types` list, so no restriction (as nginx and Apache): no installer or site type
            | depends on the web server. If a type is genuinely unsupported on one, list the
            | supported ones here and the grid will grey the rest with a reason.
            */
            'vhost_root' => env('SERVER_OLS_VHOST_ROOT', '/usr/local/lsws/conf/vhosts'),

            /*
            | The listeners a v7-layout site's wrapper attaches to: v7's own
            | `Default` (:80) and `DefaultHttps` (:443). {@see OlsDriver::renderLegacyWrapper()}
            */
            'legacy_listeners' => env('SERVER_OLS_LEGACY_LISTENERS', 'Default,DefaultHttps'),

            /*
            | LSAPI pool size, per site.
            |
            | Rendered into BOTH `maxConns` and `PHP_LSAPI_CHILDREN`: the connection limit and the
            | worker count describe the same pool and must agree.
            |
            | 35, as ServerAvatar v7 uses, so a migrated site keeps its capacity. It is a per-site
            | ceiling (LSPHP forks workers as requests arrive); lower it on a small box with many
            | busy sites.
            */
            'lsapi_children' => (int) env('SERVER_OLS_LSAPI_CHILDREN', 35),

            /*
            | Recycle an LSPHP worker after this many requests (like `pm.max_requests` on the FPM pools),
            | so a per-request leak does not accumulate until restart.
            */
            'lsapi_max_requests' => (int) env('SERVER_OLS_LSAPI_MAX_REQUESTS', 5000),
            'shared_config' => env('SERVER_OLS_CONFIG', '/usr/local/lsws/conf/httpd_config.conf'),
            // OpenLiteSpeed's own WebAdmin console, which the panel binds to 127.0.0.1
            // (panel:ols-webadmin).
            'webadmin_config' => env('SERVER_OLS_WEBADMIN_CONFIG', '/usr/local/lsws/admin/conf/admin_config.conf'),

            /*
            | The account lsws drops to, used only as a fallback: the real value is read from the
            | `user` directive in `shared_config`.
            |
            | Deliberately NOT `server.web_server_user`: that defaults to `www-data`, which install.sh
            | never writes, and a wrong account here fails silently (a grant to the wrong account
            | looks like one that worked).
            */
            'user' => env('SERVER_OLS_USER', 'nobody'),
            // A `map` is only legal inside a listener, and this names which.
            'listener' => env('SERVER_OLS_LISTENER', 'Default'),

            // OpenLiteSpeed binds certificates to a listener, not a vhost, so a site answers on 443
            // only once it is mapped here too. A box that never had TLS has no such listener;
            // registration skips it rather than failing.
            'ssl_listener' => env('SERVER_OLS_SSL_LISTENER', 'Defaultssl'),
            'test_command' => ['/usr/local/lsws/bin/openlitespeed', '-t'],

            // OpenLiteSpeed's tmp directory (`DEFAULT_TMP_DIR`, compiled in). The config test logs here
            // and reads the file back for its exit code, but test mode does not create the directory,
            // so without it the test passes on anything. OlsDriver::test() creates it.
            'tmp_dir' => env('SERVER_OLS_TMP_DIR', '/tmp/lshttpd'),
            // Restart, not reload: nothing lighter picks up a new virtual host; it is graceful.
            'reload_command' => ['/usr/local/lsws/bin/lswsctrl', 'restart'],
            'php_stack' => 'lsphp',
        ],
    ],

    'default_php_version' => env('SERVER_DEFAULT_PHP_VERSION', '8.4'),

    // What bare `php` resolves through when it exists (install.sh creates it on OpenLiteSpeed).
    // Read as the PHP screen's default before the update-alternatives group.
    'php_path_link' => env('SERVER_PHP_PATH_LINK', '/usr/local/bin/php'),

    /*
    | Where PHP-FPM puts its sockets, and the account the web server runs as. The pool's socket
    | must be reachable by that account; isolation comes from the pool's `user`, not the socket's
    | permissions, so this is deliberately the shared account.
    */

    'php_socket_dir' => env('SERVER_PHP_SOCKET_DIR', '/run/php'),
    'web_server_user' => env('SERVER_WEB_SERVER_USER', 'www-data'),

    /*
    | Server-wide PHP session directories, as other panels configure them.
    |
    | Dropped when an existing open_basedir is adopted from a migrated server: every site shares
    | them, so an open_basedir naming one would let a site read every other site's sessions. The
    | adopted site gets its own session directory instead.
    */
    'sync' => [
        /*
        | Extra things the sync should never offer, for an operator running their own stack on the
        | same box. The panel's own vhosts are detected from where this code runs, not from a name
        | (the slug is overridable and the panel is reached by domain). Both lists accept `*` globs,
        | matched case-insensitively.
        */
        'exclude' => [
            'vhosts' => [],
            'domains' => [],
        ],
    ],

    'shared_session_dirs' => [
        '/var/lib/php/sessions',
        '/var/lib/php/session',
        '/var/lib/php5/sessions',
        '/tmp/sessions',
    ],

    // How a version maps to its CLI binary, so an application's own tooling
    // runs on the same PHP that will serve it.
    'php_binary_pattern' => env('SERVER_PHP_BINARY_PATTERN', '/usr/bin/php{version}'),

    // How a version maps to its *FPM* binary. Separate from the CLI above: a server can have
    // php8.3 without php8.3-fpm, and only the second can serve a site. `apt remove` takes the
    // binary but leaves /etc/php/<v>/fpm, so the config directory alone would list removed versions.
    'php_fpm_binary_pattern' => env('SERVER_PHP_FPM_BINARY_PATTERN', '/usr/sbin/php-fpm{version}'),

    // Applications distributed through Composer are built rather than
    // unpacked, so it has to be present for those cards to work.
    'composer_binary' => env('SERVER_COMPOSER_BINARY', 'composer'),

    /*
    | Git deploys. The credential file is written 0600 and deleted as soon as the command
    | finishes; the token is never a command argument and never reaches .git/config. Clones and
    | builds are slow, so they get their own generous timeouts.
    */

    /*
    | Marketplace installers. One entry per one-click app; a site type absent
    | from this list simply has nothing to install (git, blank PHP, static).
    | Downloads are https-only and unpacked in a temp dir before being moved
    | into the web root, so a bad archive never lands on a live site.
    */

    /*
    | Applications that run their own process (Node, and anything with a start command).
    |
    | The port range sits **below the kernel's ephemeral range** (`net.ipv4.ip_local_port_range`,
    | 32768-60999 on stock Linux): an app inside it breaks intermittently when the kernel hands the
    | port to an outgoing connection. The IANA dynamic range (49152+) is wrong for the same reason.
    |
    | Ports named in `/etc/services` are skipped at allocation (a site never lands on 3306).
    | `reserved_ports` is for anything the OS does not know about.
    |
    | `memory_max` is per app: low enough that a runaway site cannot take the box down, high enough
    | for an ordinary Node app. Chosen from common practice, not measurement.
    */

    'applications' => [
        'systemd_dir' => env('SERVER_SYSTEMD_DIR', '/etc/systemd/system'),
        // How long a freshly started app must stay up, on the same process, before it counts as
        // started. A Node app missing node_modules is "active" briefly and dies a second later; the
        // unit waits 5 s before restarting, so 3 s catches the crash.
        'settle_seconds' => (int) env('SERVER_APP_SETTLE_SECONDS', 3),

        // For applications adopted from the old panel, still run by its per-user PM2 daemon. Bare, so
        // `runuser` resolves it on PATH: the old panel installed PM2 in /usr/bin but upgraded Node
        // with `n`, which moves the toolchain to /usr/local, so neither absolute path fits all.
        'pm2_binary' => env('SERVER_LEGACY_PM2_BINARY', 'pm2'),
        'pm2_timeout' => (int) env('SERVER_LEGACY_PM2_TIMEOUT', 120),

        // The old panel's agent. Its HTTPS port is hardcoded in its source, so the port is the primary
        // signal; the unit name is a build-time variable that differs per white-label build, so it
        // is a pattern.
        'legacy_agent_port' => (int) env('SERVER_LEGACY_AGENT_PORT', 43210),
        'legacy_agent_unit_pattern' => env('SERVER_LEGACY_AGENT_UNIT_PATTERN', '/(serveravatar|sa-agent)/i'),
        'legacy_agent_paths' => [
            '/usr/local/bin/serveravatar-agent',
            '/opt/serveravatar/serveravatar-agent',
            '/home/backend/agent/serveravatar-agent',
        ],

        /*
        | Asking a freshly started application for a page before calling it provisioned.
        |
        | `attempts` × `delay` has to outlast a cold boot (database connection, plugin loading; a
        | refused connection right after `systemctl start` means nothing). 15 × 2s ≈ 30 s, inside the
        | provisioning budget and well past a normal boot.
        |
        | `timeout` bounds one request; generous, since slowness is not the failure being looked for.
        */
        'readiness' => [
            'attempts' => (int) env('SERVER_READINESS_ATTEMPTS', 15),
            'delay' => (int) env('SERVER_READINESS_DELAY', 2),
            'timeout' => (int) env('SERVER_READINESS_TIMEOUT', 10),

            /*
            | How much of the application's own log to keep beside a failure. A status code says the
            | site is broken; the journal says why. Enough to include a bad startup, few enough to
            | keep the ops log readable.
            */
            'journal_lines' => (int) env('SERVER_READINESS_JOURNAL_LINES', 100),
        ],

        /*
        | How long a file manager delete stays recoverable. The trash keeps a full copy, so without a
        | window it slowly leaks disk space. A week is long enough to notice a mistake and short
        | enough that nobody stores a backup in it.
        */
        'trash_retention_days' => (int) env('SERVER_TRASH_RETENTION_DAYS', 7),

        /*
        | The largest image the file manager will render.
        |
        | This is the one file response a browser is asked to interpret, and nothing is gained past
        | it. Checked against the stat before a byte is read. Download is deliberately *not* capped
        | (see `FileBrowser::download()`): the panel must always hand back a file it accepted.
        */
        'preview_max_bytes' => (int) env('SERVER_PREVIEW_MAX_BYTES', 10 * 1024 * 1024),

        /*
        | Where supervisor keeps its program blocks.
        |
        | Written as well as read: a worker is a supervisor program, and the sync reads the same
        | directory on migrated boxes, so an adopted worker can be edited rather than only recorded.
        | One key on purpose: separate read and write keys would disagree and the panel would adopt a
        | worker it cannot manage.
        */
        'supervisor_dir' => env('SERVER_SUPERVISOR_DIR', '/etc/supervisor/conf.d'),
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
    | Held here rather than fetched. A domain pointing at Cloudflare resolves but does not reach this
    | server, so HTTP validation fails for a reason no error message mentions; recognising the
    | address lets the panel say so before a Let's Encrypt attempt is spent. The list changes rarely
    | and a network call in a form submission is worse than a stale list. Current list:
    | https://www.cloudflare.com/ips-v4
    */
    /*
    |--------------------------------------------------------------------------
    | TLS certificates
    |--------------------------------------------------------------------------
    |
    | certbot is driven in `certonly --webroot` mode, never through the `--nginx` / `--apache`
    | plugins: those edit the vhost, which the panel regenerates on every domain change, silently
    | wiping HTTPS. Writing the directives ourselves keeps one owner for the config, and webroot is
    | the only mode that works on OpenLiteSpeed (no certbot plugin).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Deployments
    |--------------------------------------------------------------------------
    |
    | Every deploy row carries command output, so unbounded history fills a self-hosted SQLite
    | database. Only the newest are kept, pruned on write (the growth is caused by deploying).
    |
    | Fifty is roughly a fortnight on an active project. Older deploys cannot be rolled back to (no
    | release directories) and the commit history lives in git.
    |
    */

    'deployments' => [
        'keep' => (int) env('SV_DEPLOYMENTS_KEEP', 50),

        // What a new git application's deploy script starts as; it is the user's file from then on.
        'default_scripts' => [
            // No `git pull`: the panel has already fetched and checked out the branch with the
            // account's credential; a pull in the script fails on private repositories.
            'php' => "cd {path}\n"
                ."if [ -f composer.json ]; then\n    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader\nfi\n",
            'node' => "cd {path}\nnpm ci\nnpm run build --if-present\n",
            'static' => "cd {path}\n",
            'proxy' => "cd {path}\n",
        ],

        // Run on a deploy with no user script and no build command, only when the checkout's
        // composer.json requires real packages: exactly the deploys the dependency check would
        // otherwise fail as composer_dependencies_missing.
        'composer_install' => "composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader\n",

        // Install + build for each package manager a Node application can record. Used as-is for the
        // create-form's build_command, and prefixed with the `cd` above for the deploy-script
        // default once an application has a package_manager on record.
        //
        // `--if-present` is npm's flag (bun matches it); yarn has no equivalent and pnpm removed
        // its, so those two check the script exists instead of failing with "Unknown option".
        'package_manager_scripts' => [
            'npm' => "npm ci\nnpm run build --if-present\n",
            'yarn' => "yarn install --frozen-lockfile\ngrep -q '\"build\"' package.json && yarn build\n",
            'pnpm' => "pnpm install --frozen-lockfile\ngrep -q '\"build\"' package.json && pnpm build\n",
            'bun' => "bun install --frozen-lockfile\nbun run build --if-present\n",
        ],

        // A Node application with no lockfile and no package manager on record: `npm ci` refuses to
        // run without package-lock.json.
        'npm_install_without_lockfile' => "npm install\nnpm run build --if-present\n",
    ],

    'certificates' => [
        'certbot' => env('SV_CERTBOT_BIN', 'certbot'),

        // One challenge directory shared by every site, aliased into all the vhost templates; per-site
        // roots would not work for node and proxy profiles, which serve nothing from disk. It is v7's
        // webroot: a v7 certificate renews with `webroot_path = /var/www/html`, so a challenge
        // served from anywhere else stops every v7 certificate renewing.
        'challenge_root' => env('SV_ACME_CHALLENGE_ROOT', '/var/www/html'),

        'live_dir' => env('SV_LETSENCRYPT_LIVE_DIR', '/etc/letsencrypt/live'),

        // One file per certificate certbot renews, whoever issued it (the panel's own included).
        // World-readable, unlike live_dir, so the panel can read it without sudo.
        'renewal_dir' => env('SV_LETSENCRYPT_RENEWAL_DIR', '/etc/letsencrypt/renewal'),

        // Where certbot runs post-renewal commands. Without a hook here the web server keeps serving
        // the old certificate from memory until something reloads it.
        'renewal_hook_dir' => env('SV_LETSENCRYPT_HOOK_DIR', '/etc/letsencrypt/renewal-hooks/deploy'),

        // Uploaded and self-signed certificates go where v7 put them: `{certs_dir}/{site}.crt` and
        // `{keys_dir}/{site}.key`, so a server moved from v7 keeps working. Not under
        // /etc/letsencrypt: certbot owns that tree and prunes what it does not recognise.
        'certs_dir' => env('SV_CUSTOM_CERT_CERTS_DIR', '/etc/ssl/certs'),
        'keys_dir' => env('SV_CUSTOM_CERT_KEYS_DIR', '/etc/ssl/private'),

        // The panel's own directory: the shared TLS-reject pair, and older uploaded/self-signed
        // certificates (still removable from here).
        'custom_dir' => env('SV_CUSTOM_CERT_DIR', '/etc/ssl/sv-oss'),

        // certbot can take two DNS lookups, an HTTP round trip and a retry; the default 60s ServerOps
        // timeout would cut a successful issuance short.
        'timeout' => 180,

        // Issuing a site's certificate and deleting the site take turns (CertificateLock).
        //
        // ttl:           longer than any live holder: the issuing job is killed at 600s and a delete
        //                is a request behind a 300s proxy timeout. 900 clears both, and is how long
        //                a holder that died without releasing blocks the next one.
        // delete_wait:   how long a delete waits for an issuance before answering 503 "busy, try
        //                again"; well inside the proxy's 300s.
        // requeue_delay: the job never waits for the lock inside a worker; held, it dispatches a
        //                fresh copy of itself this much later. Still one attempt each, so a certbot
        //                error is never retried against Let's Encrypt's rate limit.
        // max_requeues:  bounds that. 30 x 30s outlasts the ttl, so a dead holder's lock has lapsed
        //                before the job gives up and marks the certificate failed.
        'lock' => [
            'ttl' => (int) env('SV_CERTIFICATE_LOCK_TTL', 900),
            'delete_wait' => (int) env('SV_CERTIFICATE_LOCK_DELETE_WAIT', 20),
            'requeue_delay' => (int) env('SV_CERTIFICATE_LOCK_REQUEUE_DELAY', 30),
            'max_requeues' => (int) env('SV_CERTIFICATE_LOCK_MAX_REQUEUES', 30),
        ],

        // Try for a certificate on its own once a site is provisioned, when the domain already
        // points here. Off makes the panel wait to be asked (right for a box with no public DNS).
        // It declines silently either way, so a new site never opens on an SSL error.
        'auto_issue' => env('SV_AUTO_ISSUE_CERTIFICATES', true),
        // The check after a reload can reach workers that do not know the new site yet; a "not
        // served" answer is asked again this often.
        'auto_issue_attempts' => (int) env('SV_AUTO_ISSUE_ATTEMPTS', 4),
        'auto_issue_retry_seconds' => (int) env('SV_AUTO_ISSUE_RETRY_SECONDS', 2),

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

    // https://www.cloudflare.com/ips-v6 — kept apart from the IPv4 list above,
    // which DnsVerifier matches with ip2long(). The real-IP config trusts both.
    'cloudflare_ranges_v6' => [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ],

    'installer_work_dir' => env('SERVER_INSTALLER_WORK_DIR', sys_get_temp_dir()),

    // Where v7's own AI-blocker and 7G/8G files for a site are moved when the
    // owner first changes that setting in v8 (V7Leftovers). Moved, not
    // deleted: a failed config test puts them back from here.
    'v7_leftovers_backup_dir' => env('SERVER_V7_LEFTOVERS_BACKUP_DIR', '/var/lib/panel/v7-leftovers'),
    'installer_timeout' => (int) env('SERVER_INSTALLER_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Site clone
    |--------------------------------------------------------------------------
    |
    | How long the file copy may take. A clone carries the whole working copy (for git apps the
    | checkout, `.git` history and `node_modules`, usually most of the bytes on disk).
    |
    */
    // A staging push (with its optional backup first) or update from live, run on the queue.
    // StagingManager's own lock is an hour; the backup before a push can take longer.
    'staging' => [
        'job_timeout' => (int) env('SERVER_STAGING_JOB_TIMEOUT', 7200),
    ],

    'clone' => [
        'rsync_timeout' => (int) env('SERVER_CLONE_RSYNC_TIMEOUT', 900),
    ],

    /*
    | `memory_limit` for the CLI step an application runs during its own installation (Mautic's
    | `mautic:install`, Craft's `craft install`, Nextcloud's `occ`, ...).
    |
    | Set explicitly because the ambient limit differs per web server: Debian's CLI ini is
    | `memory_limit = -1`, but LiteSpeed's lsphp loads its production ini at 128M, where Mautic's
    | container compile exhausts memory (OpenLiteSpeed only).
    |
    | 512M is what Mautic's documentation asks for. Not `-1`: unbounded on a 1 GB box trades a
    | failed install for an OOM-killed server.
    |
    | Not the site's `memory_limit` from its PHP settings screen: that is written into the FPM pool
    | or the site's ini, which a CLI invocation never reads.
    */
    'installer_php_memory_limit' => env('SERVER_INSTALLER_PHP_MEMORY_LIMIT', '512M'),

    /*
    | Everything a provision or deploy does besides the slow part (directory, ownership, vhost
    | write/test/reload, creating the database, starting the unit). Added to the installer (or git
    | + build) timeout to size the queued job: a job killed at its own timeout leaves a
    | half-applied server change.
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
            // Downloads carry the version in the filename with no "latest" alias, so the installer
            // asks which release is current rather than holding a URL that 404s at the next release.
            'driver' => JoomlaInstaller::class,
            'download_url' => env('SERVER_JOOMLA_URL', ''),
            'releases_api' => env('SERVER_JOOMLA_RELEASES_API', 'https://api.github.com/repos/joomla/joomla-cms/releases/latest'),
            'db_type' => env('SERVER_JOOMLA_DB_TYPE', 'mysqli'),
            'timeout' => (int) env('SERVER_JOOMLA_TIMEOUT', 900),
        ],

        'prestashop' => [
            'driver' => PrestaShopInstaller::class,
            // PrestaShop's distribution API, not GitHub or channel.xml: their 9.x tags ship no package
            // and channel.xml still names 8.2.1. It lists every release with its PHP range and
            // package URL (the source their Docker images build from).
            'download_url' => env('SERVER_PRESTASHOP_URL', ''),
            'releases_api' => env('SERVER_PRESTASHOP_RELEASES_API', 'https://api.prestashop-project.org/prestashop'),
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
            // Distributed through Composer (no tarball), so this builds the application rather than
            // unpacking it.
            'driver' => CraftCmsInstaller::class,
            'timeout' => (int) env('SERVER_CRAFTCMS_TIMEOUT', 1800),
        ],

        'mautic' => [
            'driver' => MauticInstaller::class,
            // Mautic publishes versioned zips only, so the release is resolved, and the full package
            // taken rather than the update package, which carries changed files alone.
            'download_url' => env('SERVER_MAUTIC_URL', ''),
            'releases_api' => env('SERVER_MAUTIC_RELEASES_API', 'https://api.github.com/repos/mautic/mautic/releases/latest'),
            'config_dir' => env('SERVER_MAUTIC_CONFIG_DIR', 'config'),
            'timeout' => (int) env('SERVER_MAUTIC_TIMEOUT', 1800),
        ],

        'moodle' => [
            'driver' => MoodleInstaller::class,
            // The branch is part of the path, so a new major release means a new value here rather
            // than a silent download of the old one.
            'download_url' => env('SERVER_MOODLE_URL', 'https://packaging.moodle.org/stable500/moodle-latest-500.tgz'),
            // 75 MB, then a schema of several hundred tables to build.
            'timeout' => (int) env('SERVER_MOODLE_TIMEOUT', 1800),
        ],

        'nextcloud' => [
            'driver' => NextcloudInstaller::class,
            // Upstream publishes bzip2 and zip only. The zip: extracting the bzip2 tarball needs a
            // decompressor `tar` merely Suggests (`lbzip2`), missing on lean images. `unzip` is
            // already a required binary.
            'download_url' => env('SERVER_NEXTCLOUD_URL', 'https://download.nextcloud.com/server/releases/latest.zip'),
            'database' => env('SERVER_NEXTCLOUD_DATABASE', 'mysql'),
            // 280 MB to fetch and a schema to build afterwards; the shared default would time out.
            'timeout' => (int) env('SERVER_NEXTCLOUD_TIMEOUT', 1800),
        ],

        /*
        | The Node applications. Distributed as git repositories or npm packages rather than release
        | archives, so they do not use the shared download-and-extract helper.
        |
        | `version` is pinned to a range rather than a number: `latest` on a major-version boundary
        | silently turns an install into an upgrade nobody asked for.
        */

        'uptimekuma' => [
            'driver' => UptimeKumaInstaller::class,
            'repository' => env('SERVER_UPTIME_KUMA_REPO', 'https://github.com/louislam/uptime-kuma.git'),
            // Empty means "the newest stable release", resolved from the repository's own tags at
            // install time; a pin in a one-click installer goes stale unnoticed. Set a tag or branch
            // to hold a version deliberately.
            'branch' => env('SERVER_UPTIME_KUMA_BRANCH', ''),
            // Asked first: it distinguishes a release from a tag and marks drafts and pre-releases.
            // The *list*, not `/releases/latest`: GitHub's "latest" is newest by date, and this
            // project back-patches its old line, so "latest" could name a 1.x release; sorting the
            // list by version cannot. Empty disables it and leaves `git ls-remote` as the only
            // source.
            'releases_api' => env(
                'SERVER_UPTIME_KUMA_RELEASES_API',
                'https://api.github.com/repos/louislam/uptime-kuma/releases',
            ),
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
            // Current stable, deliberately; it moves on its own (`latest` and `stable` agree). Check:
            // `curl -s https://registry.npmjs.org/-/package/n8n/dist-tags`.
            //
            // ⚠️ Coupled to N8nSiteType::supportedNodeRange(): n8n refuses to start outside its
            // documented Node range (2.x wants Node 24), so a moving pin and a hand-written range
            // drift when n8n's next major raises its floor. Pin the major, or derive the range from
            // `engines.node` at install time, to remove the coupling.
            'version' => env('SERVER_N8N_VERSION', 'latest'),
            // Newest Node major n8n is offered on. Its Kafka client (@confluentinc/kafka-javascript)
            // ships prebuilt binaries per Node ABI; on a newer major npm builds librdkafka from
            // source, which outlasts `timeout` on a small server. Raise once a release lists the
            // newer ABI: https://github.com/confluentinc/confluent-kafka-javascript/releases
            'node_max' => env('SERVER_N8N_NODE_MAX', '24'),
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

        'adminer' => [
            'driver' => AdminerInstaller::class,
            // The newest stable release is asked for at install time, as Uptime Kuma's is. `version`
            // is only the fallback for when GitHub cannot be asked; set it, and empty
            // `releases_api`, to hold one.
            'releases_api' => env('SERVER_ADMINER_RELEASES_API', 'https://api.github.com/repos/vrana/adminer/releases'),
            'version' => env('SERVER_ADMINER_VERSION', '6.2.0'),
            // The single-file build with every bundled driver and language. Both hops
            // (github.com -> objects.githubusercontent.com) are https, as the download step requires.
            'download_url' => env('SERVER_ADMINER_URL', 'https://github.com/vrana/adminer/releases/download/v{version}/adminer-{version}.php'),
            'db_host' => env('SERVER_ADMINER_DB_HOST', '127.0.0.1'),
        ],
    ],

    'git_credential_dir' => env('SERVER_GIT_CREDENTIAL_DIR', sys_get_temp_dir()),
    'git_timeout' => (int) env('SERVER_GIT_TIMEOUT', 300),
    'build_timeout' => (int) env('SERVER_BUILD_TIMEOUT', 600),

    /*
    | Where the per-version `php` shims live.
    |
    | Deploy scripts find `php` on `PATH`. Unlike Node's per-version fnm `bin`, apt puts every PHP in
    | `/usr/bin` under a versioned name (`php8.3`), so no directory means "this version". A
    | directory holding one symlink named `php` is that directory; one per version, shared by every
    | site on it.
    |
    | Under `/var/lib`, not `/tmp`: a shim that vanishes on reboot would make the first deploy after
    | it behave differently from the last before it.
    */
    'php_shim_dir' => env('SERVER_PHP_SHIM_DIR', '/var/lib/panel/php-shims'),

    /*
    | Relabelling a site whose type is wrong (e.g. a Custom PHP site with WordPress installed by
    | hand). `Detect` reads the disk and offers the correction.
    |
    | `suggestable` is NOT the list of types the detector can return: `SiteTypeDetector` resolves an
    | `artisan` to `git`, which describes a checkout the panel owns (repository, branch, deploy
    | script, webhook) and cannot be conjured from files on disk. Only types a site can genuinely
    | *be* relabelled as belong here.
    |
    | Deliberately short: WordPress (95) and Joomla (60) are the only signatures specific enough to
    | act on; `index.php` and the no-match fallback both just mean "there is PHP here".
    */
    'site_type_detection' => [
        'suggestable' => ['wordpress', 'joomla'],

        // Types that may be relabelled *away from*, and *to* as the escape hatch. Generic: they
        // promise nothing, so leaving or arriving costs nothing.
        'generic' => ['php', 'static'],

        // Below this, say nothing: `index.php` matches at 40 on every PHP site.
        'min_confidence' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Docker
    |--------------------------------------------------------------------------
    */

    'docker' => [
        // A container with no ceiling can exhaust the box and take the panel
        // with it. Per-application overridable; never absent.
        'default_memory_limit' => env('DOCKER_DEFAULT_MEMORY_LIMIT', '512m'),

        // A database is not the application: one budget for the pair would make the app's ceiling
        // the pair's (MySQL's buffer pool alone is 128M).
        'default_db_memory_limit' => env('DOCKER_DEFAULT_DB_MEMORY_LIMIT', '512m'),

        // How long the installer waits for an app's first-run setup endpoint to answer, in
        // two-second tries. Generous: some apps migrate their database on first boot behind a 502
        // (90 is three minutes).
        'claim_attempts' => env('DOCKER_CLAIM_ATTEMPTS', 90),

        // `compose up` pulls an image the first time, which can be large on a slow link; generous so
        // a working deploy is not killed.
        'command_timeout' => (int) env('DOCKER_COMMAND_TIMEOUT', 600),

        // The first install of a Docker app (every recipe and the Container tab) pulls its images
        // inside the provisioning job, and these have no `installers.<type>.timeout`, so they would
        // get the 300 s default. This is both the job's installer allowance (`ProvisioningBudget`)
        // and the timeout of that first `compose up`; later `up`/`pull` calls keep
        // `command_timeout`. Stays under the queue worker's 1800 s TimeoutStopSec, so a restart
        // still drains a running install.
        'install_timeout' => (int) env('DOCKER_INSTALL_TIMEOUT', 1200),

        // How long a container that restarted recently is watched before it is called a crash loop.
        // One restart is the restart policy working; a loop restarts again or sits in `restarting`
        // within this window. Paid only when the first sample looked suspicious.
        'crash_loop_confirm_seconds' => (int) env('DOCKER_CRASH_LOOP_CONFIRM_SECONDS', 10),

        // Where a registry credential is materialised for the length of one pull. Mirrors
        // `git_credential_dir`: the secret must reach a CLI that only reads it from a file, so it is
        // written root-owned and removed in a `finally`.
        //
        // **Never the site's document root.** The site user can read their own tree, and is kept out
        // of the `docker` group because that group is root-equivalent.
        // Defaults to the system temp dir, like `git_credential_dir`: install.sh runs once and the
        // updater ships code, never directories, so a new default path would be missing on existing
        // boxes. The per-pull subdirectory is created on demand.
        'registry_credential_dir' => env('DOCKER_REGISTRY_CREDENTIAL_DIR', sys_get_temp_dir()),

        // The credential probe talks to a remote registry, so it needs its own ceiling:
        // `command_timeout` allows a multi-GB pull, and a login silent for 30 s will not answer.
        // The endpoint is throttled too, so a slow registry cannot occupy the panel's workers.
        'login_timeout' => (int) env('DOCKER_LOGIN_TIMEOUT', 30),

        /*
        | Image discovery: reading an image from its registry without pulling it. Every call hits
        | somebody else's server while a user waits on a form, so timeouts are short and answers
        | cached.
        */
        'images' => [
            'connect_timeout' => (int) env('DOCKER_IMAGES_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('DOCKER_IMAGES_TIMEOUT', 10),

            // A tag can be re-pushed, so its answer is cached briefly; a config
            // blob is content-addressed and never changes, so it is kept longer.
            'inspect_cache_minutes' => 10,
            'config_cache_minutes' => 60,
            'tags_cache_minutes' => 10,
            'search_cache_minutes' => 10,

            // Pages of `tags/list` followed at most. Images that tag every
            // commit (`sha-…`) have thousands; a picker needs the recent ones.
            'max_tag_pages' => 5,

            // The largest answer read from a registry, in bytes. The registry is user-chosen and every
            // answer is decoded whole (a config blob is tens of KB).
            'max_response_bytes' => 2 * 1024 * 1024,

            // "This image is X GB" from this size up.
            'large_bytes' => 1024 ** 3,

            // Null = the panel's own machine type, which is the server Docker
            // runs on. Set only to inspect for a different architecture.
            'architecture' => env('DOCKER_IMAGES_ARCHITECTURE'),

            // ENV keys that are build plumbing, not settings, hidden from the form (`Str::is`
            // patterns). Changing `NGINX_VERSION` does not change the nginx in the image; `PATH`
            // breaks it.
            'hidden_env' => [
                'PATH', 'HOME', 'HOSTNAME', 'TERM', 'SHELL', 'USER', 'PWD',
                'LANG', 'LANGUAGE', 'LC_*', 'container', 'DEBIAN_FRONTEND',
                'GPG_KEY', 'GPG_KEYS', '*_VERSION', '*_SHA256', '*_SHA512', '*_SHA1', '*_CHECKSUM',
                '*_DOWNLOAD_URL', '*_DOWNLOAD_SHA256', 'PYTHON_*', 'PIP_*', 'PYTHONDONTWRITEBYTECODE',
                'PYTHONUNBUFFERED', 'VIRTUAL_ENV', 'NODE_VERSION', 'YARN_VERSION', 'JAVA_HOME',
                'S6_*', 'LSIO_*', 'VERSION', 'BUILD_DATE', 'PHPIZE_DEPS', 'PHP_INI_DIR', 'PHP_*FLAGS',
                'PHP_URL', 'PHP_ASC_URL', 'PHP_SHA256', 'PHP_EXTRA_*', 'NGINX_*', 'NJS_*', 'PKG_RELEASE',
                'DYNPKG_RELEASE', 'GOSU_*', 'PG_MAJOR', 'PGDATA', 'LD_*', 'SSL_CERT_*',
                'NVIDIA_*', 'MALLOC_*', 'XDG_*', 'PS1', 'PHP_INI_SCAN_DIR', 'NODE_PATH', 'NPM_CONFIG_*',
                'PYTHONPATH', 'UV_*',
            ],

            // Settings an image will not start without, by repository. Images cannot declare this, so
            // it is never guessed (see ImageInspector::environment()); each entry is from the image's
            // own documentation.
            'required_env' => [
                'postgres' => ['POSTGRES_PASSWORD'],
                'mysql' => ['MYSQL_ROOT_PASSWORD'],
                'mariadb' => ['MARIADB_ROOT_PASSWORD'],
                // Crash-loops on `TypeError: Invalid URL` without it.
                'umamisoftware/umami' => ['DATABASE_URL'],
                'ghcr.io/umami-software/umami' => ['DATABASE_URL'],
            ],

            // Images that listen on a port without declaring it (no EXPOSE),
            // by repository as users write it. Reported as `guessed`.
            'known_ports' => [
                'ghcr.io/open-webui/open-webui' => 8080,
                'ghcr.io/umami-software/umami' => 3000,
                'ghcr.io/gethomepage/homepage' => 3000,
                'ghcr.io/mealie-recipes/mealie' => 9000,
                'outlinewiki/outline' => 3000,
                'requarks/wiki' => 3000,
                'n8nio/n8n' => 5678,
            ],

            // Folders an image keeps its data in without declaring them (no VOLUME), by repository.
            // Added to the declared ones, so the data survives the container being recreated on an
            // update. Paths are from each image's own documentation.
            'known_volumes' => [
                'louislam/uptime-kuma' => ['/app/data'],
                'dgtlmoon/changedetection.io' => ['/datastore'],
                'ghcr.io/dgtlmoon/changedetection.io' => ['/datastore'],
                'gotify/server' => ['/app/data'],
                'actualbudget/actual-server' => ['/data'],
                'codercom/code-server' => ['/home/coder'],
                'n8nio/n8n' => ['/home/node/.n8n'],
                'ghcr.io/open-webui/open-webui' => ['/app/backend/data'],
                'ghcr.io/gethomepage/homepage' => ['/app/config'],
            ],

            // Read the image while creating a site from it: the port when none was typed, a warning
            // when the typed one is not declared, and its VOLUMEs as the default volumes. Off in the
            // test suite, which must not reach Docker Hub.
            'inspect_on_create' => (bool) env('DOCKER_INSPECT_ON_CREATE', true),
        ],

        /*
        | After `compose up`, how long the panel waits for the site to answer. The container has to be
        | running (not restarting) and something has to answer HTTP on its loopback port, any status.
        | A first boot that runs migrations can take a minute, hence 90 s.
        */
        'readiness' => [
            // Wall-clock deadline; capped at ContainerReadinessCheck::MAX_TIMEOUT whatever is set here.
            'timeout' => (int) env('DOCKER_READINESS_TIMEOUT', 90),
            'interval' => (int) env('DOCKER_READINESS_INTERVAL', 3),
            // How long a single-image site may keep restarting, with nothing
            // answering, before the deploy stops early. A pasted compose file
            // is never stopped early: its services wait on each other.
            'restart_grace' => (int) env('DOCKER_READINESS_RESTART_GRACE', 30),
            // Log lines kept on the application beside a failure.
            'log_lines' => 50,
            // Container ports that speak something other than HTTP, so a request for `/` can never
            // pass: verified by the container's state instead. PostgreSQL, MySQL/MariaDB, Redis,
            // MongoDB, Memcached, AMQP, MQTT, NATS, Kafka, ZooKeeper, Cassandra, SMTP.
            'non_http_ports' => [5432, 3306, 6379, 27017, 11211, 5672, 1883, 4222, 9092, 2181, 9042, 25, 587],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | One-click Docker applications
    |--------------------------------------------------------------------------
    |
    | The images each Docker app runs, here rather than in its site type so an operator can pin or
    | mirror one without editing code.
    |
    | A floating tag, not a digest: a pinned one-click stops being noticed and every new site gets
    | an old release. `5-alpine` tracks Ghost 5 patches. The rendered compose file is stored per
    | site, so an existing site is NOT moved when this changes, only new ones.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Containerised database engines
    |--------------------------------------------------------------------------
    |
    | Engines a Docker box can run as shared objects for its container sites, not as applications.
    | A database has no HTTP interface, so it gets no domain, vhost or certificate (see the
    | DockerDatabase model).
    |
    | Versions are explicit image references, not a pattern with the version interpolated: a
    | transform would invent `postgres:99-alpine` for a typed version and fail at `docker pull`
    | minutes into provisioning. Verify a tag against the registry's per-tag endpoint, not a page of
    | recent tags (it lists the 60 most recently UPDATED and reports a live tag as missing).
    |
    | MySQL's 9.x innovation line is deliberately absent: a stale innovation release is the wrong
    | default to offer. 8.4 is the LTS.
    |
    | `data_path` is the directory the engine keeps its data in, which the panel mounts as a named
    | volume. `port` is the port inside the container.
    |
    */
    'docker_databases' => [
        // Where the compose files live. Root-owned and outside any site's tree: a database is not a
        // site, has no system user, and the file holds its password. Created on demand, because the
        // updater ships code rather than directories.
        'directory' => env('DOCKER_DATABASE_DIR', '/var/lib/panel/docker-databases'),

        // How long to wait for a new engine to report healthy before treating it as broken. MySQL's
        // first start initialises a data directory and can take half a minute on a small box;
        // giving up early would roll back a database that was about to work.
        'ready_timeout' => (int) env('DOCKER_DATABASE_READY_TIMEOUT', 120),

        'engines' => [
            'postgres' => [
                'label' => 'PostgreSQL',
                'port' => 5432,
                'data_path' => '/var/lib/postgresql/data',
                /*
                | Postgres 18 moved its data directory: mounting the old path makes the container
                | REFUSE TO START. In that image `PGDATA` is `/var/lib/postgresql/18/docker` and the
                | declared `VOLUME` is `/var/lib/postgresql`; 16 and 17 still use
                | `/var/lib/postgresql/data` for both.
                |
                | A per-version override rather than a second engine entry: same engine, and splitting
                | it would double the versions list in the UI.
                */
                'data_path_overrides' => [
                    '18' => '/var/lib/postgresql',
                ],
                'template' => 'server.docker.databases.postgres',
                'versions' => [
                    '18' => 'postgres:18-alpine',
                    '17' => 'postgres:17-alpine',
                    '16' => 'postgres:16-alpine',
                ],
            ],

            'mysql' => [
                'label' => 'MySQL',
                'port' => 3306,
                'data_path' => '/var/lib/mysql',
                'template' => 'server.docker.databases.mysql',
                'versions' => [
                    '8.4' => 'mysql:8.4',
                    '8.0' => 'mysql:8.0',
                ],
            ],

            'mariadb' => [
                'label' => 'MariaDB',
                'port' => 3306,
                'data_path' => '/var/lib/mysql',
                // MariaDB's image accepts the MYSQL_* variables as well as its own, so it shares
                // MySQL's template.
                'template' => 'server.docker.databases.mysql',
                'versions' => [
                    '12.3' => 'mariadb:12.3',
                    '11.8' => 'mariadb:11.8',
                    '11.4' => 'mariadb:11.4',
                ],
            ],

            'mongodb' => [
                'label' => 'MongoDB',
                'port' => 27017,
                'data_path' => '/data/db',
                'template' => 'server.docker.databases.mongodb',
                'versions' => [
                    '8' => 'mongo:8',
                    '7' => 'mongo:7',
                ],
            ],

            'redis' => [
                'label' => 'Redis',
                'port' => 6379,
                'data_path' => '/data',
                'template' => 'server.docker.databases.redis',
                'versions' => [
                    '8' => 'redis:8-alpine',
                    '7' => 'redis:7-alpine',
                ],
            ],

            'valkey' => [
                'label' => 'Valkey',
                'port' => 6379,
                'data_path' => '/data',
                // A Redis fork with the same wire protocol and `--requirepass`, so the same template.
                'template' => 'server.docker.databases.redis',
                'versions' => [
                    '9' => 'valkey/valkey:9-alpine',
                    '8' => 'valkey/valkey:8-alpine',
                ],
            ],
        ],
    ],

    'site_types' => [
        DockerSiteType::class,
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
        AdminerSiteType::class,
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
    | Brute-force containment. Server-level settings live in jail.local; per-application jails stay
    | isolated under jail.d.
    |
    | The ban action is left to fail2ban's default, not `ufw`: routing bans through UFW would let the
    | Firewall screen's on/off switch silently disable every ban.
    |
    */

    'fail2ban' => [
        'client' => env('SERVER_FAIL2BAN_CLIENT', 'fail2ban-client'),
        'jail_d' => env('SERVER_FAIL2BAN_JAIL_D', '/etc/fail2ban/jail.d'),
        'jail_local' => env('SERVER_FAIL2BAN_JAIL_LOCAL', '/etc/fail2ban/jail.local'),

        'defaults' => [
            'bantime' => (int) env('SERVER_FAIL2BAN_BANTIME', 3600),
            'findtime' => (int) env('SERVER_FAIL2BAN_FINDTIME', 600),
            'maxretry' => (int) env('SERVER_FAIL2BAN_MAXRETRY', 5),
        ],

        // The jails the panel manages. `sshd` guards the way into the server; `recidive` watches
        // fail2ban's own log and gives repeat offenders a much longer ban. Per-application jails
        // belong with the application, whose log path only exists once the app does.
        'jails' => [
            [
                'name' => 'sshd',
                'label' => 'SSH',
                // Enabling this one can lock the operator out of their own
                // server, so the API requires an explicit acknowledgement.
                'lockout_risk' => true,
                // `{ssh_port}` is replaced with the port SSH is really on; fail2ban's default would be 22,
                // so a moved SSH port would get the ban on a port nobody uses.
                //
                // `backend` belongs HERE, on the one jail that wants the journal, not in `[DEFAULT]`:
                // the systemd backend makes fail2ban ignore `logpath`, so every file-watching jail
                // (`recidive`, per-site jails) would read the journal and match nothing.
                //
                // `%(sshd_backend)s`, not a literal `systemd`: fail2ban's own variable resolves to the
                // right backend for the distribution.
                'options' => [
                    'mode' => 'aggressive',
                    'port' => '{ssh_port}',
                    'backend' => '%(sshd_backend)s',
                ],
            ],
            [
                'name' => 'recidive',
                'label' => 'Repeat offenders',
                'lockout_risk' => false,
                'options' => [
                    // The panel's copy of fail2ban's own filter, which also leaves out the per-site jails'
                    // bans: those are for one site only, and counted here three of them in a day became
                    // a week-long ban on every port, the panel and SSH included.
                    'filter' => 'panel-recidive',
                    'logpath' => '/var/log/fail2ban.log',
                    'bantime' => 604800,  // a week
                    'findtime' => 86400,
                    'maxretry' => 3,
                ],
            ],
        ],

        // Ban-time presets, so the form offers "1 hour" rather than 3600. Backend-driven so the
        // frontend does not keep its own copy of a list that must agree with the API. `-1` is
        // fail2ban's permanent ban.
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
    | Per-application fail2ban
    |--------------------------------------------------------------------------
    |
    | A different feature from `fail2ban` above, not a filtered view of it: it watches one site's own
    | access log, which only exists once the application does, in a separate drop-in so disabling
    | one never touches the other.
    |
    | Two jail templates: a generic access log cannot tell a failed login from a successful one
    | (most apps answer 200 either way), so the generic jail only rate-limits raw request volume per
    | address. WordPress gets a second, stricter jail because its login endpoints are fixed, known
    | targets.
    |
    */
    'fail2ban_apps' => [
        'jail_d' => env('SERVER_FAIL2BAN_JAIL_D', '/etc/fail2ban/jail.d'),
        // A site jail bans for that site only, in its own web-server config: the action fail2ban
        // calls, and the script it runs.
        'action_d' => env('SERVER_FAIL2BAN_ACTION_D', '/etc/fail2ban/action.d'),
        'ban_script' => env('SERVER_FAIL2BAN_BAN_SCRIPT', '/usr/local/sbin/panel-site-ban'),
        'drop_in' => env('SERVER_FAIL2BAN_APPS_DROP_IN', 'panel-apps.local'),
        'filter_d' => env('SERVER_FAIL2BAN_FILTER_D', '/etc/fail2ban/filter.d'),

        'jails' => [
            'generic' => [
                'filter' => 'panel-app-generic',
                'bantime' => 3600,
                'findtime' => 60,
                'maxretry' => 300,
            ],
            'wordpress' => [
                'filter' => 'panel-app-wplogin',
                'bantime' => 3600,
                'findtime' => 600,
                'maxretry' => 5,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Firewall
    |--------------------------------------------------------------------------
    |
    | `default_firewall_ports` are the TCP ports seeded as `allow` rules when the firewall is
    | enabled (SSH is always added on top): the panel's own service ports plus the web ports, so
    | enabling never locks the box (or its sites) out.
    |
    */

    'default_firewall_ports' => array_map('intval', array_filter(explode(
        ',', (string) env('SERVER_DEFAULT_FIREWALL_PORTS', '80,443')
    ))),

    'firewall' => [
        // Ports worth a warning that aren't a database engine we manage (those are derived from what
        // is installed): the ones that hand over the machine, or much of it, if reached from the
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
        | LSPHP. Note `{compact}`: LiteSpeed names everything `lsphp84`, not `lsphp8.4`, and assuming
        | the dot gives a "package not found" that reads like a broken repository.
        */
        'lsphp' => [
            'driver' => LsphpPhpStack::class,
            'dir' => env('SERVER_LSWS_DIR', '/usr/local/lsws'),
            'ini_path' => '{root}/lsphp{compact}/etc/php/{version}/litespeed/php.ini',
            /*
            | Two binaries, not interchangeable: `php` is the ordinary CLI used by installers; `lsphp`
            | is the LSAPI build the web server spawns (a vhost pointed at the CLI runs no PHP).
            |
            | Lists, probed in order: LSPHP is not always in the lsws tree. The /usr/bin forms have a
            | dot (`lsphp8.2`) where the lsws tree uses `lsphp82`; that asymmetry is real.
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
            | The packages LiteSpeed actually ships, which is NOT the FPM set.
            |
            | Mirroring FPM's names fails: `lsphp84-mbstring`, `-xml`, `-zip`, `-bcmath` and `-soap` do
            | not exist, and apt fails the whole transaction on one missing name, so "install PHP 8.3"
            | installed nothing. Those extensions (and gd) are compiled into the interpreter
            | (`lsphp84/bin/php -m`), so no capability is lost; `lsphp84-gd` resolves with no
            | installation candidate, which is worse than absent.
            |
            | This is install.sh's list, so a version added from the panel is the interpreter the
            | installer would have built. OpenLiteSpeedTest asserts the two stay in step.
            */
            // imagick and imap as v7 has them; LiteSpeed packages them from 8.2 up and compiles gmp in.
            // A version with no package for one is installed without it (the runtime drops an
            // extension the index does not have rather than failing).
            'base_packages' => ['common', 'mysql', 'pgsql', 'curl', 'intl', 'sqlite3', 'redis', 'igbinary', 'opcache', 'imagick', 'imap'],
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
        'openlitespeed' => ['/usr/local/lsws/bin/openlitespeed', '-t'],
    ],

    /*
    | `packages`: the dpkg package(s) whose installed version the Services
    | screen shows next to the name, first installed one wins; a pattern takes
    | the highest version it matches. A service with none, or whose package is
    | not installed, shows no version (null). PHP-FPM rows use `php{v}-fpm`.
    */
    'services' => [
        ['key' => 'nginx', 'unit' => 'nginx', 'label' => 'Nginx', 'packages' => ['nginx', 'nginx-core', 'nginx-full', 'nginx-light']],
        ['key' => 'apache', 'unit' => 'apache2', 'label' => 'Apache', 'packages' => ['apache2', 'apache2-bin']],
        ['key' => 'openlitespeed', 'unit' => 'lshttpd', 'label' => 'OpenLiteSpeed', 'packages' => ['openlitespeed']],
        /*
        | `install` names the runtime_installs row this service's install is tracked under, as
        | [runtime, version]. With it the service list shows the entry while installing and after a
        | failed install, instead of the row not existing. Config rather than a match() in the
        | manager: a match is a list somebody forgets to extend. Omit the key and the service is
        | absent until its unit exists.
        */
        /*
        | Docker. The unit is `docker`, not `docker.socket`: the socket unit is active even when the
        | daemon failed to start, which would report a working Docker on a server that cannot run a
        | container.
        */
        ['key' => 'docker', 'unit' => 'docker', 'label' => 'Docker', 'install' => ['runtime', 'docker'], 'packages' => ['docker-ce', 'docker.io']],
        ['key' => 'mysql', 'unit' => 'mysql', 'label' => 'MySQL', 'install' => ['database', 'mysql'], 'packages' => ['mysql-server-core-*', 'mysql-server']],
        ['key' => 'mariadb', 'unit' => 'mariadb', 'label' => 'MariaDB', 'install' => ['database', 'mariadb'], 'packages' => ['mariadb-server', 'mariadb-server-core']],
        ['key' => 'mongodb', 'unit' => 'mongod', 'label' => 'MongoDB', 'install' => ['database', 'mongodb'], 'packages' => ['mongodb-org-server']],
        /*
        | PostgreSQL, the one entry whose status cannot come from systemd.
        |
        | `postgresql.service` is a meta unit (`Type=oneshot`, `ExecStart=/bin/true`,
        | `RemainAfterExit=on`), so it is `active` whatever the clusters are doing, and
        | `postgresql@.service` ignores a failed start (`-` prefix), so it cannot report a dead
        | cluster either. Without `health` this screen would say "Running" while the Databases screen
        | (pg_isready) says down.
        |
        | The unit stays the meta unit because start/stop/restart through it work (the per-cluster
        | units are `PartOf=postgresql.service`); only the status is overridden.
        |
        | The port comes from the same env var as the engine's `default_port`, so they cannot drift.
        */
        // `instances`: each cluster is enabled at boot on its own, so "Start on boot" has to reach
        // them too (see runService()). `postgresql` itself is a meta package versioned like
        // `16+257`; the server is `postgresql-16`.
        ['key' => 'postgresql', 'unit' => 'postgresql', 'label' => 'PostgreSQL', 'install' => ['database', 'postgresql'], 'instances' => 'postgresql@*', 'packages' => ['postgresql-[0-9]*'],
            'health' => ['pg_isready', '--quiet', '--host=127.0.0.1', '--port='.(int) env('SERVER_POSTGRES_PORT', 5432)]],
        ['key' => 'redis', 'unit' => 'redis-server', 'label' => 'Redis', 'packages' => ['redis-server']],
        ['key' => 'supervisor', 'unit' => 'supervisor', 'label' => 'Supervisor', 'packages' => ['supervisor']],
        ['key' => 'fail2ban', 'unit' => 'fail2ban', 'label' => 'Fail2ban', 'install' => ['fail2ban', 'latest'], 'packages' => ['fail2ban']],
        // The server's own mail relay; install.sh sets it up (configure_mail). Shown only where the
        // unit exists. No `instances`: 26.04 ships a postfix@ template next to postfix.service, so
        // the pattern matched a dead unit and "Start on boot" would have enabled a second master.
        ['key' => 'postfix', 'unit' => 'postfix', 'label' => 'Postfix', 'packages' => ['postfix']],
    ],

    'protected_services' => array_values(array_filter(explode(
        ',', (string) env('SERVER_PROTECTED_SERVICES', 'nginx,php8.4-fpm')
    ))),

    /*
    | Which log sources belong to which service, as keys into the `logs` registry below: the same
    | reader, permission and incremental cursor as the Logs feature, so the frontend can poll a live
    | view. php-fpm and PostgreSQL (one log per cluster) are derived. Keys pointing at a missing
    | file are dropped at request time.
    */

    'service_logs' => [
        'nginx' => ['nginx_error', 'nginx_access'],
        'fail2ban' => ['fail2ban'],
        'apache' => ['apache_error', 'apache_access'],
        'openlitespeed' => ['openlitespeed_error', 'openlitespeed_access'],
        'mysql' => ['mysql_error', 'mysql_slow'],
        // Ubuntu's MariaDB logs to the journal, not /var/log/mysql, hence `mariadb`.
        'mariadb' => ['mysql_error', 'mysql_slow', 'mariadb'],
        'mongodb' => ['mongodb'],
        'redis' => ['redis'],
        'supervisor' => ['supervisor'],
        'postfix' => ['mail'],
    ],

    /*
    | Service usage sampling. CPU is a cumulative counter, so a percentage is measured between two
    | reads (see ServiceUsage): `window` is the oldest previous sample still worth comparing against
    | (beyond it the average describes a period nobody is watching), and `ttl` is how long a sample
    | is kept.
    */

    'usage_sample_window' => (int) env('SERVER_USAGE_SAMPLE_WINDOW', 60),
    'usage_sample_ttl' => (int) env('SERVER_USAGE_SAMPLE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Read-only server log sources the panel surfaces. No DB: the catalog is filtered at request time
    | to the files that exist on the box. php-fpm logs are detected per installed version from
    | `php_dir`. The client only references a source by its `key`; the panel resolves the path
    | from this registry (never a client path).
    |
    */

    /*
    | `clearable` marks the logs the panel will empty on request (truncate, see LogManager::clear).
    | Opt-IN per row: a source added later is not clearable until somebody decides it should be.
    |
    | `sensitive` marks logs that record what happened to the *machine* (auth.log, ufw.log,
    | fail2ban.log, syslog, kern.log, mail.log, the Let's Encrypt log). They are clearable, and the
    | flag lets the confirmation name what is destroyed: these are what an investigation needs and
    | what an intruder erases first.
    |
    | The journal is not a file, so it has no `clearable`: emptying it means `journalctl --vacuum`,
    | which discards the host's entire journal, a different operation with a different blast radius.
    */
    'logs' => [
        // Web server (only the installed one has files)
        ['key' => 'nginx_access', 'label' => 'Nginx — Access', 'group' => 'web', 'path' => '/var/log/nginx/access.log', 'clearable' => true],
        ['key' => 'nginx_error', 'label' => 'Nginx — Error', 'group' => 'web', 'path' => '/var/log/nginx/error.log', 'clearable' => true],
        ['key' => 'apache_access', 'label' => 'Apache — Access', 'group' => 'web', 'path' => '/var/log/apache2/access.log', 'clearable' => true],
        ['key' => 'apache_error', 'label' => 'Apache — Error', 'group' => 'web', 'path' => '/var/log/apache2/error.log', 'clearable' => true],
        // Both, like the other two. The shipped httpd_config.conf writes `accessLog logs/access.log`,
        // which resolves against $SERVER_ROOT.
        // `privileged`: /usr/local/lsws/logs is root:nogroup 0750, so the panel account cannot see
        // these files and plain `file` sources would be dropped. Read through sudo.
        ['key' => 'openlitespeed_access', 'label' => 'OpenLiteSpeed — Access', 'group' => 'web', 'kind' => 'privileged', 'path' => '/usr/local/lsws/logs/access.log', 'clearable' => true],
        ['key' => 'openlitespeed_error', 'label' => 'OpenLiteSpeed — Error', 'group' => 'web', 'kind' => 'privileged', 'path' => '/usr/local/lsws/logs/error.log', 'clearable' => true],
        // Database (installed engine)
        ['key' => 'mysql_error', 'label' => 'MySQL — Error', 'group' => 'database', 'path' => '/var/log/mysql/error.log', 'clearable' => true],
        ['key' => 'mysql_slow', 'label' => 'MySQL — Slow Query', 'group' => 'database', 'path' => '/var/log/mysql/mariadb-slow.log', 'clearable' => true],
        // MariaDB on Debian/Ubuntu logs to the journal, not /var/log/mysql, so the file entry above
        // never appears. Tagged `mariadbd` by the unit; shown only once it has written something.
        ['key' => 'mariadb', 'label' => 'MariaDB', 'group' => 'database', 'kind' => 'journal', 'identifier' => 'mariadbd', 'path' => '', 'hide_when_empty' => true],
        ['key' => 'mongodb', 'label' => 'MongoDB', 'group' => 'database', 'path' => '/var/log/mongodb/mongod.log', 'clearable' => true],
        // Cache
        ['key' => 'redis', 'label' => 'Redis', 'group' => 'cache', 'path' => '/var/log/redis/redis-server.log', 'clearable' => true],
        // System
        ['key' => 'syslog', 'label' => 'System — Syslog', 'group' => 'system', 'path' => '/var/log/syslog', 'clearable' => true, 'sensitive' => true],
        ['key' => 'auth', 'label' => 'System — Auth', 'group' => 'system', 'path' => '/var/log/auth.log', 'clearable' => true, 'sensitive' => true],
        // Hardware, drivers, and the OOM killer — the log that answers "the
        // site did not crash, it was killed".
        ['key' => 'kernel', 'label' => 'System — Kernel', 'group' => 'system', 'path' => '/var/log/kern.log', 'clearable' => true, 'sensitive' => true],
        // Only present where an MTA is installed; the source hides itself on servers that have none.
        ['key' => 'mail', 'label' => 'System — Mail', 'group' => 'system', 'path' => '/var/log/mail.log', 'clearable' => true, 'sensitive' => true],
        // Security / daemons
        ['key' => 'ufw', 'label' => 'Firewall — UFW', 'group' => 'security', 'path' => '/var/log/ufw.log', 'clearable' => true, 'sensitive' => true],
        // Also what the `recidive` jail reads to find repeat offenders.
        ['key' => 'fail2ban', 'label' => 'Fail2ban', 'group' => 'security', 'path' => '/var/log/fail2ban.log', 'clearable' => true, 'sensitive' => true],
        ['key' => 'supervisor', 'label' => 'Supervisor', 'group' => 'daemon', 'path' => '/var/log/supervisor/supervisord.log', 'clearable' => true],

        // `kind` is `file` unless it says otherwise — read natively, followed
        // by byte offset, downloadable. The two below cannot be any of that.

        // certbot's directory is 0700 root, so the panel account cannot stat inside it. Read through
        // ServerOps, like the unattended upgrades log.
        ['key' => 'letsencrypt', 'label' => "Let's Encrypt", 'group' => 'security', 'kind' => 'privileged', 'path' => '/var/log/letsencrypt/letsencrypt.log', 'clearable' => true, 'sensitive' => true],

        // Not a file at all — a binary store read through journalctl. `path` is
        // empty because there is nothing to open.
        ['key' => 'journal', 'label' => 'System — Journal', 'group' => 'system', 'kind' => 'journal', 'path' => ''],
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
    | only those whose dependency is present surface). How much to keep is the
    | user's choice per clean (KeepDays), not config; the service-log glob set
    | is config so it can be tuned per box.
    |
    */

    'disk_path' => env('SERVER_DISK_PATH', '/'),

    'disk_cleaner' => [
        'targets' => [
            AptCacheTarget::class,
            AptOrphansTarget::class,
            DockerImagesTarget::class,
            JournalTarget::class,
            RotatedLogsTarget::class,
            ServiceLogsTarget::class,
            SiteLogsTarget::class,
            TmpTarget::class,
        ],

        // Active service log files truncated by `service_logs` (glob patterns;
        // only existing files are touched). Covers our supported services.
        'service_log_globs' => [
            '/var/log/nginx/*.log',
            '/var/log/apache2/*.log',
            '/usr/local/lsws/logs/*.log',
            // Sites' own logs are not listed: they live in each site's `logs/` directory on every stack
            // and have their own category, SiteLogsTarget.
            '/var/log/mysql/*.log',
            '/var/log/mongodb/*.log',
            '/var/log/redis/*.log',
            '/var/log/supervisor/*.log',
            '/var/log/php*-fpm.log',
            // Not ufw.log or fail2ban.log: they record attacks, and fail2ban's recidive jail reads its
            // own log, so emptying it forgets every repeat offender. logrotate already keeps both in
            // check.
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Paths + option sets for the server Settings feature. Changes go to managed, NON-DESTRUCTIVE
    | drop-ins (never the distro's own config), so a migrated server keeps its configuration.
    | Overridable for tests.
    |
    */

    /*
    | Rewritten when the hostname changes: `hostnamectl` sets the name and
    | nothing else, and a name that does not resolve makes every `sudo` call
    | wait out a DNS timeout first.
    */
    'hosts_file' => env('SERVER_HOSTS_FILE', '/etc/hosts'),

    'sshd_config_dir' => env('SERVER_SSHD_CONFIG_DIR', '/etc/ssh/sshd_config.d'),

    // Read after the drop-ins, for a port set by hand (App\Support\SshPort).
    'sshd_config_file' => env('SERVER_SSHD_CONFIG_FILE', '/etc/ssh/sshd_config'),

    // Present only when the kernel has IPv6: a host booted with ipv6.disable=1 has no such file, and
    // an nginx site listening on [::] fails its config test there (NginxDriver). install.sh's
    // have_ipv6 asks the same file.
    'ipv6_probe_file' => env('SERVER_IPV6_PROBE_FILE', '/proc/net/if_inet6'),

    /*
    |--------------------------------------------------------------------------
    | Container login
    |--------------------------------------------------------------------------
    |
    | A Docker app's own system user logs in straight into the app's container: sshd forces every
    | login of a member of `group` through `login`, which runs `helper` as root through one NOPASSWD
    | rule, and the helper reaches only the containers `map` says that user owns. See
    | AppShellInstaller.
    |
    | `sshd_drop_in` sorts right after SecuritySettings' 00-panel.conf: sshd keeps the FIRST value it
    | reads for a keyword, so the block must precede anything that sets forwarding or a forced
    | command globally.
    |
    | `sync` is off under phpunit (see phpunit.xml), like privilege.sudo: a map rewrite after every
    | Application save would show up in every command assertion. AppShellMapTest turns it on.
    |
    */

    'app_shell' => [
        'group' => env('SERVER_APP_SHELL_GROUP', 'sv-app-shell'),
        'map' => env('SERVER_APP_SHELL_MAP', '/etc/panel-app-shell/map.json'),
        'helper' => env('SERVER_APP_SHELL_HELPER', '/usr/local/libexec/panel/app-shell'),
        'login' => env('SERVER_APP_SHELL_LOGIN', '/usr/local/libexec/panel/app-login'),
        'sudoers' => env('SERVER_APP_SHELL_SUDOERS', '/etc/sudoers.d/panel-app-shell'),
        'sshd_drop_in' => '01-panel-app-shell.conf',
        'sync' => (bool) env('SERVER_APP_SHELL_SYNC', true),
    ],

    'unattended_upgrades_file' => env('SERVER_UNATTENDED_UPGRADES', '/etc/apt/apt.conf.d/99-panel-upgrades'),

    'reboot_required_file' => env('SERVER_REBOOT_REQUIRED', '/var/run/reboot-required'),

    /*
    | How many updates are waiting: the script the MOTD uses. It reads the apt cache directly (no
    | lock, no network, no `apt-get update`) and prints `updates;security` to **stderr**.
    |
    | It ships in `update-notifier-common`, which a minimal install may not have. Absent, the counts
    | read `null`, deliberately not `0`, which would claim the box is up to date on no evidence.
    */
    'apt_check' => env('SERVER_APT_CHECK', '/usr/lib/update-notifier/apt-check'),

    /*
    | Touched by APT::Periodic only when `apt-get update` *succeeded*; the mtime of
    | /var/lib/apt/lists moves on failed runs too, so it would report a refresh that did not happen.
    */
    'apt_update_stamp' => env('SERVER_APT_UPDATE_STAMP', '/var/lib/apt/periodic/update-success-stamp'),

    /*
    | Its directory is root:adm 0750, so the panel user cannot open this
    | directly — it is read through ServerOps (sudo tail), not File::get.
    */
    'unattended_upgrades_log' => env('SERVER_UNATTENDED_LOG', '/var/log/unattended-upgrades/unattended-upgrades.log'),

    /*
    | Where the *reason* is when a package fails to configure. The log above is
    | unattended-upgrades' own narration; dpkg's output (maintainer script stderr, conffile prompts,
    | unsatisfied dependencies) goes only here.
    */
    'unattended_upgrades_dpkg_log' => env(
        'SERVER_UNATTENDED_DPKG_LOG',
        '/var/log/unattended-upgrades/unattended-upgrades-dpkg.log',
    ),

    /*
    | Installing the waiting security updates on demand.
    |
    | `unattended-upgrade` (singular) is the binary; `unattended-upgrades` is the service. Running
    | the binary applies the same Allowed-Origins policy the drop-in configures, whether or not
    | apt's timer is enabled.
    |
    | The timeout covers both halves: ServerOps waits out the dpkg lock for up to ten minutes (40
    | attempts, 15s apart) before the upgrade starts, so 900 would leave too little to install.
    | 30 minutes is the whole budget, and the queue job's timeout matches it.
    */
    'security_updates' => [
        'binary' => env('SERVER_UNATTENDED_UPGRADE_BIN', '/usr/bin/unattended-upgrade'),
        'timeout' => (int) env('SERVER_SECURITY_UPDATE_TIMEOUT', 1800),
    ],

    'redis_cli' => env('SERVER_REDIS_CLI', '/usr/bin/redis-cli'),
    // The file install.sh appends its Redis settings to; read (as root) by
    // `redis:repair-installer-policy` to find the installer's own lines.
    'redis_conf' => env('SERVER_REDIS_CONF', '/etc/redis/redis.conf'),

    /*
    |--------------------------------------------------------------------------
    | Runtimes
    |--------------------------------------------------------------------------
    |
    | Language versions the server can hold several of at once, shown under Settings. Node is
    | managed with fnm, not nvm: a systemd unit's ExecStart needs an absolute binary path and has no
    | shell to source a profile in, and fnm keeps every version at a fixed path. Installed
    | system-wide so one copy serves every site user.
    |
    */

    // A plain scheduled reboot, separate from unattended-upgrades' reboot
    // after a required update.
    'reboot_schedule' => [
        'file' => env('SERVER_REBOOT_SCHEDULE_FILE', 'panel-reboot'),
        'default_hour' => (int) env('SERVER_REBOOT_SCHEDULE_HOUR', 3),
        // On the hour the user picked, as in v7 ("04:00" must not restart at 04:11); movable here.
        'minute' => (int) env('SERVER_REBOOT_SCHEDULE_MINUTE', 0),
        // Where a switched-off schedule's day/hour are kept, so switching it
        // back on offers what the administrator had. Panel-owned, never cron.
        'remembered' => env('SERVER_REBOOT_SCHEDULE_REMEMBERED', storage_path('app/reboot-schedule.json')),
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
            // A Node installed outside fnm (v7's nodesource package). While it
            // is there the panel does not link its default in front of it.
            'outside_binary' => env('SERVER_NODE_OUTSIDE_BINARY', '/usr/bin/node'),
            // Newest patch of this many majors, so the picker is a list
            // somebody can read rather than every release ever made.
            'installable_majors' => (int) env('SERVER_NODE_INSTALLABLE_MAJORS', 6),
            // Offer versions Node itself has stopped supporting.
            //
            // Off: a dead line gets no security fixes and native modules stop shipping prebuilt
            // binaries for it (a one-click n8n install failed compiling on Node 21).
            //
            // On restores the old list for a server migrating an application that needs a dead
            // runtime. Installed versions are never hidden by this, only the offer to add new ones.
            'offer_eol' => filter_var(env('SERVER_NODE_OFFER_EOL', false), FILTER_VALIDATE_BOOLEAN),
            'install_timeout' => (int) env('SERVER_NODE_INSTALL_TIMEOUT', 900),
            // PM2 is installed per Node version, for applications that run more than one process.
            // Pinned, not `@latest`: servers provisioned a week apart would get different majors, and
            // re-running the install would upgrade PM2 under running applications.
            'pm2_version' => env('SERVER_PM2_VERSION', '6.0.13'),
        ],

        // Where "the newest npm this Node version can run" comes from. Read by a scheduled command
        // into `npm_releases`, never inside a request (see the lifecycle URLs below).
        'npm' => [
            'registry_url' => env('SERVER_NPM_REGISTRY_URL', 'https://registry.npmjs.org/npm'),
            'timeout' => (int) env('SERVER_NPM_REGISTRY_TIMEOUT', 30),
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

        'fail2ban' => [
            // Same apt output as PHP, so the same codes. Its own entry, not pointed at PHP's: the
            // classifier looks up by runtime name, and a shared list would drift silently.
            'failure_reasons' => [
                'package_not_found' => '/Unable to locate package|has no installation candidate/i',
                'apt_lock' => '/Could not get lock|Unable to acquire the dpkg frontend lock/i',
                'network' => '/Temporary failure resolving|Failed to fetch|Connection timed out/i',
                'no_space' => '/No space left on device/i',
            ],
        ],

        // A curl download from GitHub, not apt, so its own reasons: the
        // shared `network` sentence talks about a package repository.
        'wp_cli' => [
            'failure_reasons' => [
                'network' => '/Could not resolve host|Failed to connect|Connection timed out|Operation timed out|SSL/i',
                'no_space' => '/No space left on device|Failure writing output/i',
            ],
        ],

        'php' => [
            // Offer PHP versions whose apt candidate is an alpha, beta, RC or dev build. Off: they looked
            // exactly like a release. For testing a pre-release on purpose.
            'offer_prerelease' => (bool) env('SERVER_PHP_OFFER_PRERELEASE', false),

            // The oldest PHP offered for a new install. Older versions already installed are still
            // listed and usable; this only stops offering releases long past end of life (5.6 to
            // 7.3 appear in ondrej's repository).
            'min_offered' => (string) env('SERVER_PHP_MIN_OFFERED', '7.4'),

            /*
            | Why an install failed, matched against apt's output in order.
            |
            | These become `reason` codes on the PHP screen, rendered into a sentence in the viewer's
            | locale; the raw output is never shown (internal paths, untranslatable). An unmatched
            | failure is `unknown`, which still carries a reference to the server-ops log.
            */
            'failure_reasons' => [
                'package_not_found' => '/Unable to locate package|has no installation candidate/i',
                'apt_lock' => '/Could not get lock|Unable to acquire the dpkg frontend lock/i',
                'network' => '/Temporary failure resolving|Failed to fetch|Connection timed out/i',
                'no_space' => '/No space left on device/i',
            ],

            // A bare phpX.Y-fpm has no mysql, curl or mbstring, so every marketplace application would
            // fail on it.
            'base_packages' => array_values(array_filter(explode(',', (string) env(
                'SERVER_PHP_BASE_PACKAGES',
                // 🔴 This is install.sh's list, and must stay identical to it. `sqlite3` is the one
                // that hurt: the panel's own database is SQLite, so a version installed from the
                // panel and made the default left bare `php` unable to open it ("could not find
                // driver"). PhpRuntimeTest asserts the parity, as OlsInstallerTest does for
                // OpenLiteSpeed.
                'fpm,cli,common,mysql,pgsql,curl,mbstring,xml,zip,gd,intl,bcmath,soap,sqlite3,redis,igbinary,opcache,imagick,imap,gmp'
            )))),
            'install_timeout' => (int) env('SERVER_PHP_INSTALL_TIMEOUT', 900),

            // SAPIs an extension is toggled in. All together: cli and fpm diverging means a site that
            // works in a browser and fails in a cron deploy.
            'sapis' => array_values(array_filter(explode(',', (string) env('SERVER_PHP_SAPIS', 'cli,fpm')))),

            // Share the phpX.Y- prefix but are not extensions (SAPIs, shared config, headers): nothing
            // to toggle, so nothing to list.
            'non_extension_packages' => array_values(array_filter(explode(',', (string) env(
                'SERVER_PHP_NON_EXTENSION_PACKAGES',
                'cli,common,dev,fpm,cgi,phpdbg,embed,litespeed,all_dev'
            )))),

            // Modules the panel itself needs. Turning one off under the panel's own version means the
            // request to turn it back on is never answered.
            'panel_required' => array_values(array_filter(explode(',', (string) env(
                'SERVER_PHP_PANEL_REQUIRED',
                'curl,mbstring,xml,dom,tokenizer,fileinfo,pdo,pdo_sqlite,sqlite3,phar,openssl,simplexml,xmlwriter,ctype'
            )))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ionCube Loader
    |--------------------------------------------------------------------------
    |
    | Commercial PHP applications (WHMCS and most licensed scripts) ship encrypted source and cannot
    | run without this loader. No apt package: it is a closed-source `.so` published by ionCube,
    | one per PHP version, installed as a `zend_extension`.
    |
    | ⚠️ The vendor publishes no checksum file, so the download is verified by TLS, a size cap and an
    | ELF header check, and the installed file's SHA-256 is reported so an operator can compare it
    | across servers. That is a sanity check, not a supply-chain guarantee. Nothing installs it
    | unless somebody presses the button.
    |
    */
    'ioncube' => [
        // Architecture decides the archive. Both are published; the ELF check at install refuses a
        // file whose machine type disagrees with the URL it came from.
        'urls' => [
            'x86_64' => env('SERVER_IONCUBE_URL_X86_64', 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz'),
            'aarch64' => env('SERVER_IONCUBE_URL_AARCH64', 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_aarch64.tar.gz'),
        ],

        // Which PHP versions ionCube publishes a loader for, so the screen can say "not supported"
        // without downloading 29 MB. A hint, not the authority: the install refuses on the archive
        // itself. The archive carries 4.2 through 8.5 but NOT 8.0, which the panel still offers.
        'versions' => array_values(array_filter(explode(',', (string) env(
            'SERVER_IONCUBE_VERSIONS',
            '8.1,8.2,8.3,8.4,8.5'
        )))),

        // Loaded before OPcache's `10-`; that ordering is load-bearing: loading ionCube after OPcache
        // can prevent PHP from starting.
        'ini_name' => env('SERVER_IONCUBE_INI_NAME', '01-ioncube.ini'),

        // The archive is about 29 MB. The cap catches a response that is not the archive at all, not
        // a slightly larger release.
        'max_bytes' => (int) env('SERVER_IONCUBE_MAX_BYTES', 104857600),
        'timeout' => (int) env('SERVER_IONCUBE_TIMEOUT', 300),
    ],

    'redis_maxmemory_policies' => [
        'noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random',
        'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl',
    ],

    // Swap: a single managed swap file. Only this file + its fstab line are ever touched
    // (non-destructive: a migrated server keeps existing swap).
    //
    // `/swapfile-panel`, and it has to be: install.sh creates a file by that name, and `enabled` is
    // answered by looking for *this* path in the kernel's swap list. A plain `/swapfile` may already
    // be someone else's, and `createOrResize()` runs `rm -f` over this path, so it must be a name
    // the panel owns.
    //
    // ⚠️ A panel that created swap through Settings before this change has it at `/swapfile`, now
    // unmanaged (reported under `unmanaged` / `system_total`, not resizable or removable from the
    // screen). `SERVER_SWAP_FILE=/swapfile` restores the old behaviour. No auto-adoption of a bare
    // `/swapfile`: its fstab line carries no mark of who wrote it (see `SwapSettings::isActive()`).
    'swap_file' => env('SERVER_SWAP_FILE', '/swapfile-panel'),

    'swap_max_mb' => (int) env('SERVER_SWAP_MAX_MB', 65536), // 64 GB ceiling

    // Disk left free after a swap file is created or resized. The new file is built while the old
    // one still exists, so its size is not counted as free. A request leaving less than this is
    // refused.

    'swap_reserve_mb' => (int) env('SERVER_SWAP_RESERVE_MB', 1024),

    'fstab' => env('SERVER_FSTAB', '/etc/fstab'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard / metrics
    |--------------------------------------------------------------------------
    |
    | The dashboard reads facts and live metrics cheaply from `/proc` (+ df). `sample_interval` is
    | the short window (seconds) used to compute the rate for cumulative counters (CPU %, network):
    | two reads that far apart. `metrics.retention_hours` bounds the `server_metrics` table (the
    | 5-min collector prunes older rows). All overridable so tests can point `proc_dir`/`os_release`
    | at fixtures and set the interval to 0.
    |
    */

    'proc_dir' => env('SERVER_PROC_DIR', '/proc'),

    /*
    | The cgroup v2 mount. Read only when systemd reports a unit's memory or
    | task count as `[not set]` (OpenLiteSpeed turns its accounting off) —
    | see ServiceUsage::withCgroupFallback(). Overridable for test fixtures.
    */
    'cgroup_dir' => env('SERVER_CGROUP_DIR', '/sys/fs/cgroup'),

    /*
    | The cloud metadata service, used only to learn this server's own public address when the
    | machine cannot see it on any interface.
    |
    | 169.254.169.254 is link-local: the request never leaves the host's network segment. An
    | external "what is my IP" service would contact a third party on a dashboard load, which a
    | self-hosted panel should not do unasked. Bounded tightly: on bare metal nothing answers, and
    | the timeout is paid on a dashboard request.
    */
    'metadata_base' => env('SERVER_METADATA_BASE', 'http://169.254.169.254'),
    'metadata_timeout' => (int) env('SERVER_METADATA_TIMEOUT', 2),

    'os_release' => env('SERVER_OS_RELEASE', '/etc/os-release'),

    // Whole block devices live directly under here; partitions live beneath
    // them. That distinction is what stops disk I/O being counted twice.
    'sys_block' => env('SERVER_SYS_BLOCK', '/sys/block'),

    /*
     * The per-minute size sweep.
     *
     * Both default to 0: every site, every minute, so list sizes are never more than a minute old.
     *
     * Know the cost before raising the site count: `du` walks every inode, so this job's cost
     * scales with the customer's data, and it evicts the page cache the sites are served from. On
     * a server with dozens of large sites a pass will not finish inside the minute, and
     * `withoutOverlapping()` then keeps it running forever.
     *
     * Bound it rather than turn it off: `per_run` caps how many sites one tick may walk, and
     * `stale_minutes` skips any measured more recently than that. Sites are taken
     * least-recently-measured first, so a bounded sweep still reaches every site over several
     * ticks.
     */
    'application_size' => [
        'stale_minutes' => (int) env('SERVER_APPLICATION_SIZE_STALE_MINUTES', 0),
        'per_run' => (int) env('SERVER_APPLICATION_SIZE_PER_RUN', 0),
    ],

    /*
     * How much of a failed command's stdout is kept in the server-ops log: enough for the error a
     * tool printed before dying. The tail is kept, not the head, since progress output comes first
     * and the reason last.
     */
    'log_output_limit' => (int) env('SERVER_LOG_OUTPUT_LIMIT', 4000),

    'metrics' => [
        'sample_interval' => (int) env('SERVER_METRICS_SAMPLE_INTERVAL', 1),
        // Oldest previous poll still worth measuring against on the live endpoint; past this the
        // average describes a window nobody is watching, so it reports zero.
        'rate_window' => (int) env('SERVER_METRICS_RATE_WINDOW', 120),
        // Device names counted for disk I/O. Real disk types only: loop devices and device-mapper
        // nodes are left out because their traffic is already counted on the disk underneath.
        'disk_devices' => env('SERVER_METRICS_DISK_DEVICES', '/^(sd|nvme|vd|xvd|hd|md)/'),
        'retention_hours' => (int) env('SERVER_METRICS_RETENTION_HOURS', 24),
        'processes_limit' => (int) env('SERVER_METRICS_PROCESSES_LIMIT', 25),
        // How long Stop waits for a signalled process to exit before saying it is still running
        // (TERM is a request, not a guarantee).
        'stop_wait_seconds' => (int) env('SERVER_PROCESS_STOP_WAIT_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Databases
    |--------------------------------------------------------------------------
    |
    | The Databases feature manages MySQL / MariaDB / PostgreSQL / MongoDB via a DatabaseEngine
    | strategy. The per-engine admin connection lives in the `database_connections` table (NOT
    | here); this file holds the static engine capabilities, client binaries, protected system
    | objects and the charset/collation whitelist (identifiers can't be parameterised in DDL, so
    | the whitelist IS the injection guard). Client names + the auth-file dir are overridable so
    | tests can fake the Process calls.
    |
    */

    'databases' => [
        'engines' => [
            // `installer` is what makes an engine offerable in the setup page.
            'mysql' => ['label' => 'MySQL', 'driver' => 'sql', 'client' => env('SERVER_MYSQL_CLIENT', 'mysql'), 'dump_client' => env('SERVER_MYSQLDUMP', 'mysqldump'), 'default_port' => 3306, 'default_socket' => '/var/run/mysqld/mysqld.sock', 'dump_extension' => 'sql', 'uri_scheme' => 'mysql', 'installer' => MySqlInstaller::class, 'service' => 'mysql', 'remote_bind_file' => env('SERVER_MYSQL_REMOTE_BIND_FILE', '/etc/mysql/mysql.conf.d/zz-panel-remote.cnf'), 'legacy_remote_bind_files' => ['/etc/mysql/mysql.conf.d/99-panel-remote.cnf']],
            'mariadb' => ['label' => 'MariaDB', 'driver' => 'sql', 'client' => env('SERVER_MARIADB_CLIENT', 'mariadb'), 'dump_client' => env('SERVER_MARIADBDUMP', 'mariadb-dump'), 'default_port' => 3306, 'default_socket' => '/var/run/mysqld/mysqld.sock', 'dump_extension' => 'sql', 'uri_scheme' => 'mariadb', 'installer' => MariaDbInstaller::class, 'service' => 'mariadb', 'remote_bind_file' => env('SERVER_MARIADB_REMOTE_BIND_FILE', '/etc/mysql/mariadb.conf.d/99-panel-remote.cnf')],
            /*
            | `unsupported_codenames`: Ubuntu releases this engine's vendor has not published for.
            |
            | MongoDB publishes per Ubuntu codename, and for `resolute` (26.04) ships only
            | `mongodb-database-tools`: no `mongodb-org`, `mongodb-org-server` or `mongodb-mongosh`.
            | Listed here so the setup page greys the card instead of offering a button that writes an
            | apt source and key, waits two minutes, then fails. Unlike PHP (sury.org) there is no
            | second source.
            |
            | **This list goes stale by design:** it states what a vendor has published, and wrongly
            | refuses once MongoDB ships for resolute. Override per server with
            | SERVER_MONGO_UNSUPPORTED (comma-separated, empty string to clear); the install itself
            | still classifies the real failure as `os_unsupported` if this list is ever too
            | optimistic.
            */
            'mongodb' => ['label' => 'MongoDB', 'driver' => 'mongo', 'client' => env('SERVER_MONGO_CLIENT', 'mongosh'), 'dump_client' => env('SERVER_MONGODUMP', 'mongodump'), 'restore_client' => env('SERVER_MONGORESTORE', 'mongorestore'), 'default_port' => 27017, 'default_socket' => null, 'dump_extension' => 'archive.gz', 'uri_scheme' => 'mongodb', 'installer' => MongoDbInstaller::class, 'unsupported_codenames' => array_filter(array_map('trim', explode(',', (string) env('SERVER_MONGO_UNSUPPORTED', 'resolute'))))],
            // Installable from Ubuntu's own archive: no third-party repository, unlike MongoDB.
            //
            // `uri_scheme` is `postgresql`, not the engine name: that is the scheme libpq and every
            // client library accept (`pgsql://` opens nowhere).
            //
            // The default socket is a *directory*: libpq takes the directory holding `.s.PGSQL.5432`
            // as its host, and naming the file is an error.
            'postgresql' => ['label' => 'PostgreSQL', 'driver' => 'pgsql', 'client' => env('SERVER_PSQL_CLIENT', 'psql'), 'dump_client' => env('SERVER_PGDUMP', 'pg_dump'), 'restore_client' => env('SERVER_PGRESTORE', 'pg_restore'), 'default_port' => (int) env('SERVER_POSTGRES_PORT', 5432), 'default_socket' => '/var/run/postgresql', 'dump_extension' => 'sql', 'uri_scheme' => 'postgresql', 'installer' => PostgresInstaller::class],
        ],

        /*
        | MongoDB's apt repository (not in Ubuntu's archive). Every value is overridable because the
        | series is a moving target: MongoDB drops old per-series lists, and a panel pinned to a
        | series that stopped being published installs nothing on a new OS release.
        |
        | `codename` is deliberately empty: it is read from the server's own /etc/os-release, because
        | a codename MongoDB does not publish for adds a list with no packages. Set it only to
        | override that detection, e.g. to point a newer Ubuntu at the last codename MongoDB
        | published for.
        |
        | MongoDB Community is SSPL, which is not OSI-approved. The panel installs it on request; it
        | does not ship it.
        */
        'mongodb' => [
            'series' => env('SERVER_MONGO_SERIES', '8.0'),
            'repository_url' => env('SERVER_MONGO_REPO_URL', 'https://repo.mongodb.org/apt/ubuntu'),
            'key_url' => env('SERVER_MONGO_KEY_URL', 'https://pgp.mongodb.com/server-8.0.asc'),
            'component' => env('SERVER_MONGO_REPO_COMPONENT', 'multiverse'),
            'architecture' => env('SERVER_MONGO_ARCH', 'amd64'),
            'codename' => env('SERVER_MONGO_CODENAME', ''),
            'codename_fallback' => env('SERVER_MONGO_CODENAME_FALLBACK', 'noble'),
            'packages' => ['mongodb-org'],
            // Detection targets the *server*, not the meta-package or the shell: `mongosh` alone is a
            // box that talks to someone else's MongoDB, and treating it as installed would offer
            // databases the panel cannot make.
            'server_package' => env('SERVER_MONGO_SERVER_PACKAGE', 'mongodb-org-server'),
            'service' => env('SERVER_MONGO_SERVICE', 'mongod'),
            'config_file' => env('SERVER_MONGO_CONFIG', '/etc/mongod.conf'),
        ],

        /*
        | PostgreSQL's packages. Ubuntu's own archive, so no repository or key (the `mongodb` block
        | above exists because MongoDB is not in the archive).
        |
        | `postgresql` is the version-tracking metapackage: it pulls whatever major the distribution
        | ships. Pinning a major would stop working when the distribution moves on.
        |
        | Detection targets the same name: client packages sit on plenty of boxes with no server, and
        | treating one as installed would offer databases the panel cannot make.
        */
        'postgresql' => [
            'packages' => ['postgresql'],
            'server_package' => env('SERVER_POSTGRES_SERVER_PACKAGE', 'postgresql'),
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

        /*
        | Per-driver facts. One block per `driver` above, and every driver must appear here:
        | `DatabaseEngineCatalogTest` fails the build otherwise.
        |
        | These used to be inline `driver === 'mongo' ? x : y`, where `y` silently meant MySQL. A
        | third driver would have inherited MySQL's answers, including `system_schemas`, a
        | deny-by-default guard: its own system databases would have been droppable. A missing key is
        | a build-time test failure instead of a wrong answer at runtime.
        */
        'drivers' => [
            'sql' => [
                // Never created/dropped/altered by the panel.
                'system_schemas' => ['information_schema', 'mysql', 'performance_schema', 'sys'],
                // Whitelist for validation: charset => allowed collations. Identifiers cannot be
                // parameterised in DDL, so the whitelist IS the injection guard.
                'charsets' => [
                    'utf8mb4' => ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_bin'],
                    'utf8mb3' => ['utf8mb3_general_ci', 'utf8mb3_unicode_ci'],
                    'latin1' => ['latin1_swedish_ci', 'latin1_general_ci'],
                    'ascii' => ['ascii_general_ci'],
                ],
                // `RENAME USER` carries the password across, so changing both at once needs a second
                // statement. Mongo has no rename: it drops and recreates, applying the new password.
                'rename_keeps_password' => true,
                // MySQL and MongoDB both carry the host in the account itself,
                // so a remote user is created by creating it.
                'supports_remote_users' => true,
            ],
            'mongo' => [
                'system_schemas' => ['admin', 'config', 'local'],
                'charsets' => [],
                'rename_keeps_password' => false,
                'supports_remote_users' => true,
            ],
            'pgsql' => [
                // The cluster's own databases. `template0` cannot be connected to, and `postgres` is the
                // maintenance database every administrative statement runs against: dropping it would
                // take the panel's own access with it.
                'system_schemas' => ['postgres', 'template0', 'template1'],
                // PostgreSQL's ENCODING, and LC_COLLATE as the "collation". Per driver because MySQL's
                // list does not overlap (`utf8mb4` is not a PostgreSQL encoding), and offering it would
                // pass the form and be refused by the engine.
                //
                // The locale values assume a glibc cluster initialised with UTF-8, as every
                // Debian/Ubuntu package does. `C` is always present.
                'charsets' => [
                    'UTF8' => ['C', 'C.UTF-8', 'en_US.UTF-8'],
                    'LATIN1' => ['C'],
                    'SQL_ASCII' => ['C'],
                ],
                // `ALTER ROLE … RENAME` keeps the password under scram-sha-256 (the default), so a
                // rename plus a password change needs the second statement, as in MySQL.
                'rename_keeps_password' => true,
                // A MySQL account's host is half its identity, so `CREATE USER 'a'@'10.0.0.5'` grants
                // access from there. A PostgreSQL role is cluster-wide with no host: remote access is
                // `pg_hba.conf` (who may authenticate), `listen_addresses` (bound off-box at all) and
                // the firewall (5432 reachable). The panel owns all three (see PgsqlEngine's
                // remote-access section and DatabaseFirewall), so the preference is applied, not
                // stored and ignored.
                'supports_remote_users' => true,
            ],
        ],
        // `postgres` is PostgreSQL's own superuser. Names starting with `pg_` are reserved by
        // PostgreSQL too and refused by the requests.
        'system_users' => ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'debian-sys-maint', 'mariadb.sys', 'postgres'],

        'default_charset' => 'utf8mb4',
        'default_collation' => 'utf8mb4_unicode_ci',

        // 0600 temp auth files (--defaults-extra-file) are written here so a DB
        // password is never passed on argv (visible in `ps`).
        'auth_file_dir' => env('SERVER_DB_AUTH_DIR', sys_get_temp_dir()),

        // Where database exports (dumps) are written + streamed from.
        'export_dir' => env('SERVER_DB_EXPORT_DIR', storage_path('app/db-exports')),

        // Uploaded files waiting to be imported into a database, and the
        // cleaned SQL an import loads. The panel's own, 0700: no site user may
        // read a customer's dump or swap the file before it is loaded.
        'import_dir' => env('SERVER_DB_IMPORT_DIR', storage_path('app/db-imports')),

        // The largest file an import accepts (2 GB). The disk check runs against this as well as the
        // free space.
        'import_max_bytes' => (int) env('SERVER_DB_IMPORT_MAX_BYTES', 2 * 1024 * 1024 * 1024),

        // A run: the safety backup, the load, and putting the backup back if
        // the load fails — three passes over a database that can be gigabytes.
        'import_timeout' => (int) env('SERVER_DB_IMPORT_TIMEOUT', 7200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Git provider integrations
    |--------------------------------------------------------------------------
    |
    | Connected accounts used to reach private repositories. `fields` is the connect-form schema the
    | API hands the frontend, because providers differ: GitLab can point at a self-hosted host, and
    | Bitbucket addresses everything through a workspace (its scoped Access Tokens authenticate as
    | the token, not as a user).
    |
    | Outbound calls are bounded: a slow provider must never hold a request open.
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
    | Deploy-on-push. One verifier per provider, because the three sign differently: GitHub's header
    | is `X-Hub-Signature-256` and Bitbucket's is `X-Hub-Signature`, the same scheme under a name
    | that differs by a suffix.
    |
    | `timestamp_tolerance` bounds replay on GitLab's signed deliveries (the timestamp is inside the
    | signed content; a captured delivery would otherwise stay valid forever). Five minutes covers
    | clock skew and queueing.
    |
    | `delivery_memory` is how long a delivery id is remembered so a retried or replayed delivery
    | does not deploy twice (providers retry on timeout; it is routine).
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

    /*
    |--------------------------------------------------------------------------
    | API rate limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute, per authenticated user (`api`) or per IP for guests. Applied to every API
    | route by bootstrap/app.php.
    |
    | A per-route `throttle:N,1` does NOT replace this: both buckets must pass, so the lower wins. A
    | higher per-route limit only takes effect if the route drops `throttle:api`.
    |
    */

    'rate_limits' => [
        'api' => (int) env('RATE_LIMIT_API', 180),
        'guest' => (int) env('RATE_LIMIT_GUEST', 20),
        // The central panel arrives as one machine account for every site it manages here; its own
        // budget so a vendor operating several sites does not share one person's allowance.
        'central' => (int) env('RATE_LIMIT_CENTRAL', 3000),
        // Progress-polling endpoints only. They are exempt from the `api` budget above, so this
        // number stands on its own. High on purpose: watched screens poll for as long as a job runs,
        // and watching an install closely must not look like an attack.
        'progress' => (int) env('RATE_LIMIT_PROGRESS', 600),
    ],

];
