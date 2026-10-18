<?php

/*
 * install.sh's fresh-server check, run for real: preflight() against a file
 * tree laid out like a server under a temporary root, with every command it
 * asks (ss, systemctl, mysql, psql, mongosh, redis-cli, docker) stubbed.
 *
 * Why (2026-10-18): the one-command install was run by mistake on a busy dev
 * server — 26 sites, MariaDB, Redis, PHP-FPM pools — and preflight accepted
 * it. The installer upgraded packages, installed Docker and Postfix, rewrote
 * redis.conf and restarted Redis, and changed that server's PHP-FPM, before
 * failing at step 14. Adarsh: "the installer should stop on the servers that
 * aren't fresh. Also, we don't want false positives."
 */

/** Ubuntu release => [codename, PHP]. */
const FRESH_RELEASES = [
    '22.04' => ['jammy', '8.1'],
    '24.04' => ['noble', '8.3'],
    '26.04' => ['resolute', '8.5'],
];

/** A file under $root, with its directories. */
function freshPut(string $root, string $path, string $content = ''): void
{
    @mkdir(dirname($root.$path), 0777, true);
    file_put_contents($root.$path, $content);
}

function freshDir(string $root, string $path): void
{
    @mkdir($root.$path, 0777, true);
}

/** What a stubbed command answers: $name is the stub's answer file. */
function freshAnswer(array $box, string $name, ?string $content, int $exit = 0): void
{
    if ($content === null) {
        @unlink($box['answers'].'/'.$name);

        return;
    }
    file_put_contents($box['answers'].'/'.$name, $content);
    file_put_contents($box['answers'].'/'.$name.'.exit', (string) $exit);
}

/**
 * A stock cloud image of this release with everything §3 of the card lists as
 * harmless on it: snapd and lxd, cloud-init, unattended-upgrades, every
 * provider's guest agent, a swap file, ufw and fail2ban, sshd drop-ins, extra
 * sudo users, an MTA, Python, Node and PHP CLIs, Docker Engine with nothing
 * in it, an empty MariaDB (or MySQL), PostgreSQL, MongoDB and Redis, PHP-FPM
 * with only `www`, and nginx and Apache serving only their default pages.
 *
 * @return array{dir: string, root: string, bin: string, answers: string}
 */
function freshImage(string $release = '24.04'): array
{
    [$codename, $php] = FRESH_RELEASES[$release];
    $dir = sys_get_temp_dir().'/installer-fresh-'.bin2hex(random_bytes(4));
    $box = ['dir' => $dir, 'root' => $dir.'/root', 'bin' => $dir.'/bin', 'answers' => $dir.'/answers'];
    foreach ([$box['root'], $box['bin'], $box['answers']] as $d) {
        mkdir($d, 0777, true);
    }
    $r = $box['root'];

    freshPut($r, '/etc/os-release', "PRETTY_NAME=\"Ubuntu {$release} LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"{$release}\"\nVERSION_CODENAME={$codename}\nID=ubuntu\nID_LIKE=debian\n");
    freshPut($r, '/etc/passwd', "root:x:0:0:root:/root:/bin/bash\nsyslog:x:104:111::/home/syslog:/usr/sbin/nologin\nubuntu:x:1000:1000:Ubuntu:/home/ubuntu:/bin/bash\nadmin:x:1001:1001::/home/admin:/bin/bash\n");
    freshPut($r, '/etc/group', "root:x:0:\nadm:x:4:syslog,ubuntu\nsudo:x:27:ubuntu,admin\n");
    foreach (['snapd', 'cloud-init', 'unattended-upgrades', 'qemu-guest-agent', 'open-vm-tools', 'droplet-agent',
        'do-agent', 'hc-utils', 'google-guest-agent', 'walinuxagent', 'ufw', 'fail2ban', 'postfix'] as $pkg) {
        freshPut($r, "/var/lib/dpkg/info/{$pkg}.list", "/usr/share/doc/{$pkg}\n");
    }
    freshPut($r, '/var/lib/dpkg/info/libc6:amd64.list', "/lib\n");
    freshDir($r, '/snap/lxd/current');
    freshDir($r, '/snap/amazon-ssm-agent/current');
    freshPut($r, '/usr/bin/snap');
    freshPut($r, '/etc/cloud/cloud.cfg', "users: [default]\n");
    freshPut($r, '/proc/swaps', "Filename\tType\tSize\tUsed\tPriority\n/swapfile file 2097148 0 -2\n");
    freshPut($r, '/etc/ufw/user.rules', "-A ufw-user-input -p tcp --dport 22 -j ACCEPT\n");
    freshPut($r, '/etc/fail2ban/jail.local', "[sshd]\nenabled = true\n");
    freshPut($r, '/etc/ssh/sshd_config.d/50-cloud-init.conf', "PasswordAuthentication no\n");
    freshPut($r, '/etc/sudoers.d/90-cloud-init-users', "ubuntu ALL=(ALL) NOPASSWD:ALL\n");
    freshPut($r, '/usr/sbin/sendmail');
    freshPut($r, '/etc/postfix/main.cf', "inet_interfaces = loopback-only\n");
    foreach (['python3', 'node', 'php'] as $cli) {
        freshPut($r, "/usr/bin/{$cli}");
    }
    freshDir($r, '/home/ubuntu/.ssh');
    freshDir($r, '/home/admin');

    // nginx and Apache with only their welcome pages.
    freshPut($r, '/etc/nginx/nginx.conf', "include /etc/nginx/conf.d/*.conf;\ninclude /etc/nginx/sites-enabled/*;\n");
    freshDir($r, '/etc/nginx/conf.d');
    freshPut($r, '/etc/nginx/sites-available/default', "server { listen 80 default_server; }\n");
    freshDir($r, '/etc/nginx/sites-enabled');
    symlink($r.'/etc/nginx/sites-available/default', $r.'/etc/nginx/sites-enabled/default');
    freshPut($r, '/etc/apache2/sites-enabled/000-default.conf', "<VirtualHost *:80>\n</VirtualHost>\n");
    freshPut($r, '/etc/apache2/sites-available/default-ssl.conf', "<VirtualHost *:443>\n</VirtualHost>\n");
    freshPut($r, '/var/www/html/index.nginx-debian.html', '<h1>Welcome to nginx!</h1>');
    freshPut($r, '/var/www/html/index.html', '<h1>It works!</h1>');

    // Package-default database servers and Redis, empty.
    freshPut($r, '/usr/sbin/mariadbd');
    foreach (['mysql', 'performance_schema', 'sys', '#innodb_redo', '#innodb_temp'] as $db) {
        freshDir($r, '/var/lib/mysql/'.$db);
    }
    freshPut($r, '/var/lib/mysql/ibdata1');
    freshPut($r, '/var/lib/mysql/aria_log.00000001');
    freshAnswer($box, 'mysql', "root\nmysql\nmariadb.sys\nPUBLIC\n");
    freshDir($r, '/usr/lib/postgresql/16/bin');
    foreach (['1', '4', '5', 'pgsql_tmp'] as $oid) {
        freshDir($r, '/var/lib/postgresql/16/main/base/'.$oid);
    }
    freshAnswer($box, 'psql', '');
    freshPut($r, '/usr/bin/mongod');
    freshAnswer($box, 'mongosh', "db admin\ndb config\ndb local\nusers 0\n");
    freshPut($r, '/usr/bin/redis-server');
    freshAnswer($box, 'redis-cli', "# Keyspace\r\n");
    freshPut($r, "/etc/php/{$php}/fpm/pool.d/www.conf", "[www]\nuser = www-data\nlisten = /run/php/php{$php}-fpm.sock\n");

    // Docker Engine, running, with nothing in it.
    freshPut($r, '/usr/bin/dockerd');
    freshDir($r, '/var/lib/docker/containers');
    freshAnswer($box, 'active-docker', '');
    freshAnswer($box, 'docker-ps', '');
    freshAnswer($box, 'docker-images', '');

    freshDir($r, '/etc/letsencrypt/live');
    freshPut($r, '/etc/letsencrypt/live/README', "This directory contains your keys and certificates.\n");

    freshAnswer($box, 'ss', implode("\n", [
        'LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=700,fd=3))',
        'LISTEN 0 4096 127.0.0.53%lo:53 0.0.0.0:* users:(("systemd-resolve",pid=500,fd=14))',
        'LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=812,fd=6))',
    ])."\n");

    freshStubs($box);

    return $box;
}

/** Stub commands answering from $box['answers']; every call goes to $dir/calls. */
function freshStubs(array $box): void
{
    $a = escapeshellarg($box['answers']);
    $calls = escapeshellarg($box['dir'].'/calls');
    $answer = fn (string $name) => 'f='.$a.'/'.$name.'; [ -f "$f" ] || exit 1; cat "$f"; exit "$(cat "$f.exit" 2>/dev/null || echo 0)"';

    $stubs = [
        // ss filters on `sport = :N` the way the real one does.
        'ss' => 'f='.$a.'/ss; [ -f "$f" ] || exit 0; port=""
for x in "$@"; do case "$x" in "sport = :"*) port="${x#sport = :}" ;; esac; done
if [ -n "$port" ]; then awk -v p=":$port" '."'substr(\$4, length(\$4) - length(p) + 1) == p'".' "$f"; else cat "$f"; fi',
        'systemctl' => 'if [ "$1" = is-active ]; then for u in "$@"; do :; done; [ -f '.$a.'/active-"$u" ]; exit $?; fi; exit 0',
        'docker' => 'case "$1" in ps) '.$answer('docker-ps').' ;; images) '.$answer('docker-images').' ;; esac; exit 1',
        'mysql' => $answer('mysql'),
        'runuser' => $answer('psql'),
        'mongosh' => $answer('mongosh'),
        'redis-cli' => $answer('redis-cli'),
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents($box['bin'].'/'.$name, "#!/bin/sh\necho \"{$name} \$*\" >>{$calls}\n{$body}\n");
        chmod($box['bin'].'/'.$name, 0755);
    }
}

/** Every path under the root with its size and content hash: "changed nothing" made checkable. */
function freshSnapshot(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $path => $info) {
        $files[substr($path, strlen($root))] = $info->isLink() ? 'link' : ($info->isDir() ? 'dir' : md5_file($path));
    }
    ksort($files);

    return $files;
}

/**
 * preflight() on this box, as root on this release, reporting to a local
 * Central. Then, when it passes, $after.
 *
 * @param  array<string, string>  $vars  FORCE, DRY_RUN, …
 * @return array{code: int, out: string, reports: array<int, array<string, mixed>>, before: array, after: array, calls: string, log: ?string, state: ?string}
 */
function freshPreflight(array $box, array $vars = [], string $after = ''): array
{
    $r = $box['root'];
    file_put_contents($box['dir'].'/router.php', '<?php file_put_contents(__DIR__."/got.jsonl", file_get_contents("php://input"), FILE_APPEND); http_response_code(204);');
    $port = random_int(20000, 40000);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $box['dir'].'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    // Listening before the first report, or curl's refused connection loses it.
    for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1); $i++) {
        usleep(100_000);
    }

    // As root on this release: the two lines preflight cannot be pointed elsewhere.
    $preflight = str_replace(
        ['[[ $EUID -eq 0 ]]', '/etc/os-release'],
        ['[[ 0 -eq 0 ]]', $r.'/etc/os-release'],
        installerFn('preflight'),
    );

    $set = '';
    foreach ($vars + ['FORCE' => '0', 'DRY_RUN' => '0'] as $k => $v) {
        $set .= $k.'='.escapeshellarg($v)."\n";
    }

    $script = 'set -Eeuo pipefail
        trap '.escapeshellarg('echo "ERR-TRAP line $LINENO: $BASH_COMMAND"').' ERR
        '.installerFunctions(
        'log_tail', 'report_failure', 'save_install_state', 'send_progress', 'json_str', 'json_text', 'one_line',
        'report_log_tail', 'validate_central_inputs', 'dry_run_plan',
        'fresh_note', 'fresh_ok', 'fresh_warn', 'fresh_found', 'fresh_count', 'fresh_join', 'fresh_names', 'fresh_lines',
        'fresh_entries', 'fresh_has_package', 'fresh_server_rerun', 'fresh_check_panels', 'fresh_check_legacy_agent',
        'fresh_check_web_servers', 'fresh_check_databases', 'fresh_check_redis', 'fresh_check_fpm', 'fresh_check_docker',
        'fresh_check_site_data', 'fresh_list_harmless', 'fresh_server_check',
    )."\n".$preflight.'
        say() { printf "%s\n" "$*"; }
        ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; warn() { echo "warn: $*"; }
        step() { STEP_NO=$((STEP_NO + 1)); CURRENT_STEP="$*"; echo "step: $*"; save_install_state installing; }
        die() { report_failure "$*"; exit 1; }
        BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
        STEP_NO=0; STEP_TOTAL=0; CURRENT_STEP="starting up"; LOGIN_STATUS=0; INSTALL_STARTED=1700000000
        FRESH_CHECK=""; FRESH_FINDINGS=(); FRESH_NOTES=(); FAIL_CODE=""; FAIL_FINDINGS=(); REDACT=()
        FRESH_ROOT='.escapeshellarg($r).'
        PANEL_SLUG=panel; APP_USER=panel; APP_DIR='.escapeshellarg($r.'/var/www/panel').'
        INSTALL_STATE_DIR='.escapeshellarg($r.'/var/lib/panel-install').'
        LOG_FILE='.escapeshellarg($r.'/var/log/panel-install.log').'
        PANEL_ADMIN_USERNAME=""; PANEL_ADMIN_PASSWORD=""; PROGRESS_READY=0
        CENTRAL_TOKEN="central-tok"; PROGRESS_URL="http://127.0.0.1:'.$port.'/p"
        STACK=lemp; WEB_SERVER=nginx; PANEL_HOST=panel.example.com
        PATH='.escapeshellarg($box['bin']).':$PATH
        '.$set.'
        preflight
        echo "PREFLIGHT-PASSED FRESH_CHECK=$FRESH_CHECK"
        '.$after;

    $before = freshSnapshot($r);
    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);
    usleep(300_000); // the background "installing" report
    proc_terminate($server);

    $reports = array_values(array_filter(array_map(
        fn (string $line) => json_decode($line, true),
        preg_split('/\R/', (string) @file_get_contents($box['dir'].'/got.jsonl')) ?: [],
    )));

    return [
        'code' => $code,
        'out' => implode("\n", $out),
        'reports' => $reports,
        'before' => $before,
        'after' => freshSnapshot($r),
        'calls' => (string) @file_get_contents($box['dir'].'/calls'),
        'log' => is_file($r.'/var/log/panel-install.log') ? (string) file_get_contents($r.'/var/log/panel-install.log') : null,
        'state' => is_file($r.'/var/lib/panel-install/state') ? (string) file_get_contents($r.'/var/lib/panel-install/state') : null,
    ];
}

/** The failed report Central got, if any. */
function freshFailedReport(array $run): ?array
{
    foreach ($run['reports'] as $report) {
        if (($report['state'] ?? null) === 'failed') {
            return $report;
        }
    }

    return null;
}

/** It stopped at step 1, through die, with nothing on the server changed. */
function expectStoppedUnchanged(array $run): void
{
    expect($run['code'])->toBe(1, $run['out'])
        ->and($run['out'])->not->toContain('PREFLIGHT-PASSED')
        ->and($run['out'])->not->toContain('ERR-TRAP')
        ->and($run['out'])->toContain("This server isn't fresh, so the install stopped.")
        ->and($run['after'])->toBe($run['before'])
        ->and($run['log'])->toBeNull()
        ->and($run['state'])->toBeNull();
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/installer-fresh-*') ?: [] as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

describe('a stock image', function () {
    it('passes on Ubuntu :dataset with everything an image ships', function (string $release) {
        $run = freshPreflight(freshImage($release));

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=passed')
            ->and($run['out'])->not->toContain('ERR-TRAP')
            ->and($run['out'])->not->toContain('warn: ')
            ->and(freshFailedReport($run))->toBeNull();

        // Every harmless thing is printed, so the log shows it was checked.
        foreach ([
            'ok: no other control panel', 'ok: nginx serves only its default page', 'ok: Apache serves only its default page',
            'ok: MariaDB has only its own system databases', 'ok: PostgreSQL has only its own system databases',
            'ok: MongoDB has only its own system databases', 'ok: Redis is empty', 'ok: PHP-FPM has only its default www pool',
            'ok: Docker Engine has no containers or images', 'ok: no site files in /var/www',
            'ok: no public_html and no certificates', 'ok: snapd is installed (harmless)', 'ok: cloud-init is installed (harmless)',
            'ok: qemu-guest-agent is installed (harmless)', 'ok: amazon-ssm-agent is installed (harmless)',
            'ok: walinuxagent is installed (harmless)', 'ok: swap is on (harmless)', 'ok: ufw is installed (harmless)',
            'ok: fail2ban is installed (harmless)', 'ok: postfix is installed (harmless)', 'ok: sudo users: ubuntu, admin (harmless)',
            'ok: node is installed (harmless without sites)', 'ok: this server is fresh',
            'skip: port 80 is served by nginx, which the panel will share',
        ] as $line) {
            expect($run['out'])->toContain($line);
        }

        // The log, once it exists, holds the check's record.
        expect($run['log'])->toContain("FRESH_CHECK=passed\n")
            ->and($run['log'])->toContain('fresh-server check: ok: Redis is empty')
            ->and($run['log'])->toContain('fresh-server check: ok: this server is fresh');
    })->with(array_keys(FRESH_RELEASES));

    it('passes a bare image with none of the optional software at all', function () {
        $box = freshImage();
        foreach (['/etc/nginx', '/etc/apache2', '/var/www', '/usr/sbin/mariadbd', '/var/lib/mysql', '/usr/lib/postgresql',
            '/usr/bin/mongod', '/usr/bin/redis-server', '/etc/php', '/usr/bin/dockerd', '/etc/letsencrypt'] as $path) {
            exec('rm -rf '.escapeshellarg($box['root'].$path));
        }
        freshAnswer($box, 'ss', 'LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=700,fd=3))'."\n");

        $run = freshPreflight($box);

        expect($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=passed')
            ->and($run['out'])->not->toContain('ERR-TRAP')
            // Nothing that is not installed is asked.
            ->and($run['calls'])->not->toMatch('/^(mysql|runuser|mongosh|redis-cli|docker|systemctl) /m');
    });

    it('never asks a stopped Docker daemon, which its socket would start', function () {
        $box = freshImage();
        freshAnswer($box, 'active-docker', null);

        $run = freshPreflight($box);

        expect($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=passed')
            ->and($run['out'])->toContain('ok: Docker Engine has no containers or images')
            ->and($run['calls'])->not->toMatch('/^docker /m');
    });
});

describe('what an image never has', function () {
    it('stops on :dataset, naming it, and changes nothing', function (Closure $add, string $finding) {
        $box = freshImage();
        $add($box, $box['root']);

        $run = freshPreflight($box);

        expectStoppedUnchanged($run);
        expect($run['out'])->toContain('Found: '.$finding.'.');

        $report = freshFailedReport($run);
        expect($report)->not->toBeNull()
            ->and($report['code'])->toBe('not_fresh')
            ->and($report['findings'])->toBe([$finding])
            ->and($report['step'])->toBe(1);
    })->with([
        'cPanel' => [fn ($b, $r) => freshDir($r, '/usr/local/cpanel'), 'another control panel: cPanel/WHM (/usr/local/cpanel)'],
        'Plesk' => [fn ($b, $r) => freshDir($r, '/usr/local/psa'), 'another control panel: Plesk (/usr/local/psa)'],
        'DirectAdmin' => [fn ($b, $r) => freshDir($r, '/usr/local/directadmin'), 'another control panel: DirectAdmin (/usr/local/directadmin)'],
        'CyberPanel' => [fn ($b, $r) => freshDir($r, '/usr/local/CyberCP'), 'another control panel: CyberPanel (/usr/local/CyberCP)'],
        'CloudPanel' => [fn ($b, $r) => freshPut($r, '/usr/bin/clpctl'), 'another control panel: CloudPanel (/usr/bin/clpctl)'],
        'HestiaCP' => [fn ($b, $r) => freshDir($r, '/usr/local/hestia'), 'another control panel: HestiaCP (/usr/local/hestia)'],
        'VestaCP' => [fn ($b, $r) => freshDir($r, '/usr/local/vesta'), 'another control panel: VestaCP (/usr/local/vesta)'],
        'aaPanel' => [fn ($b, $r) => freshDir($r, '/www/server/panel'), 'another control panel: aaPanel (/www/server/panel)'],
        'Webmin' => [fn ($b, $r) => freshDir($r, '/etc/webmin'), 'another control panel: Webmin/Virtualmin (/etc/webmin)'],
        'ISPConfig' => [fn ($b, $r) => freshDir($r, '/usr/local/ispconfig'), 'another control panel: ISPConfig (/usr/local/ispconfig)'],
        'RunCloud' => [fn ($b, $r) => freshDir($r, '/RunCloud'), 'another control panel: RunCloud (/RunCloud)'],
        'Ploi' => [fn ($b, $r) => freshDir($r, '/home/ploi'), 'another control panel: Ploi (/home/ploi)'],
        'Forge' => [fn ($b, $r) => freshDir($r, '/home/forge'), 'another control panel: Forge (/home/forge)'],
        'GridPane' => [fn ($b, $r) => freshDir($r, '/opt/gridpane'), 'another control panel: GridPane (/opt/gridpane)'],
        'ServerPilot' => [fn ($b, $r) => freshDir($r, '/opt/sp'), 'another control panel: ServerPilot (/opt/sp)'],
        'SpinupWP' => [fn ($b, $r) => freshDir($r, '/etc/spinupwp'), 'another control panel: SpinupWP (/etc/spinupwp)'],
        'the v7 agent' => [function ($b, $r) {
            file_put_contents($b['answers'].'/ss', 'LISTEN 0 4096 *:43210 *:* users:(("sureshcloud-age",pid=4242,fd=7))'."\n", FILE_APPEND);
            freshPut($r, '/proc/4242/cgroup', "0::/system.slice/sureshcloud.service\n");
        }, 'the v7 agent (port 43210, sureshcloud.service)'],
        'an nginx site' => [fn ($b, $r) => freshPut($r, '/etc/nginx/sites-enabled/shop.example.com', "server { server_name shop.example.com; }\n"), 'nginx with 1 site (shop.example.com)'],
        'an nginx site in conf.d' => [fn ($b, $r) => freshPut($r, '/etc/nginx/conf.d/blog.conf', "server {\n  server_name blog.example.com;\n}\n"), 'nginx with 1 site (blog.conf)'],
        'an Apache site' => [fn ($b, $r) => freshPut($r, '/etc/apache2/sites-enabled/shop.conf', "<VirtualHost *:80>\n</VirtualHost>\n"), 'Apache with 1 site (shop.conf)'],
        'an OpenLiteSpeed site' => [function ($b, $r) {
            freshDir($r, '/usr/local/lsws/conf/vhosts/Example');
            freshDir($r, '/usr/local/lsws/conf/vhosts/shop');
            freshPut($r, '/usr/local/lsws/conf/httpd_config.conf', "virtualhost Example {\n}\nvirtualhost shop {\n}\n");
        }, 'LiteSpeed with 1 site (shop)'],
        'a Caddy site' => [fn ($b, $r) => freshPut($r, '/etc/caddy/Caddyfile', "{\n  email a@example.com\n}\n(common) {\n  encode gzip\n}\nshop.example.com {\n  root * /srv\n}\n"), 'Caddy with 1 site (shop.example.com)'],
        'a MariaDB database' => [fn ($b, $r) => freshDir($r, '/var/lib/mysql/shop'), 'MariaDB with 1 database (shop)'],
        'a MariaDB user' => [fn ($b) => freshAnswer($b, 'mysql', "root\nmysql\nmariadb.sys\nwp\n"), 'MariaDB with 1 user (wp)'],
        'a MySQL database' => [function ($b, $r) {
            unlink($r.'/usr/sbin/mariadbd');
            freshPut($r, '/usr/sbin/mysqld');
            freshDir($r, '/var/lib/mysql/crm');
            freshAnswer($b, 'mysql', "root\nmysql.infoschema\nmysql.session\nmysql.sys\ndebian-sys-maint\n");
        }, 'MySQL with 1 database (crm)'],
        'a PostgreSQL database' => [fn ($b, $r) => freshDir($r, '/var/lib/postgresql/16/main/base/16384'), 'PostgreSQL with 1 database'],
        'a PostgreSQL user' => [fn ($b) => freshAnswer($b, 'psql', "app\n"), 'PostgreSQL with 1 user (app)'],
        'a MongoDB database' => [fn ($b) => freshAnswer($b, 'mongosh', "db admin\ndb config\ndb local\ndb forum\nusers 0\n"), 'MongoDB with 1 database (forum)'],
        'a MongoDB user' => [fn ($b) => freshAnswer($b, 'mongosh', "db admin\ndb config\ndb local\nusers 2\n"), 'MongoDB with 2 users'],
        'a Redis key' => [fn ($b) => freshAnswer($b, 'redis-cli', "# Keyspace\r\ndb0:keys=1,expires=0,avg_ttl=0\r\n"), 'Redis with 1 key'],
        'a PHP-FPM pool' => [fn ($b, $r) => freshPut($r, '/etc/php/8.3/fpm/pool.d/shop.conf', "[shop]\nuser = shop\n"), 'PHP-FPM with 1 pool (shop)'],
        'a Docker container' => [fn ($b) => freshAnswer($b, 'docker-ps', "3f2a1b\n"), 'Docker with 1 container'],
        'a Docker image' => [fn ($b) => freshAnswer($b, 'docker-images', "sha256abc\n"), 'Docker with 1 image'],
        'a stopped Docker\'s container on disk' => [function ($b, $r) {
            freshAnswer($b, 'active-docker', null);
            freshDir($r, '/var/lib/docker/containers/3f2a1b');
        }, 'Docker with 1 container'],
        'site files in /var/www' => [fn ($b, $r) => freshPut($r, '/var/www/shop/index.php', '<?php'), 'site files in /var/www (shop)'],
        'a page beside the default one' => [fn ($b, $r) => freshPut($r, '/var/www/html/index.php', '<?php'), 'site files in /var/www (html/index.php)'],
        'a public_html' => [fn ($b, $r) => freshDir($r, '/home/bob/public_html'), 'public_html in 1 home (bob)'],
        'a certificate' => [fn ($b, $r) => freshDir($r, '/etc/letsencrypt/live/shop.example.com'), "1 Let's Encrypt certificate (shop.example.com)"],
    ]);

    it('stops once for several findings, naming them all in one plain sentence', function () {
        $box = freshImage();
        $r = $box['root'];
        for ($i = 1; $i <= 12; $i++) {
            freshPut($r, sprintf('/etc/nginx/sites-enabled/s%02d.example.com', $i), "server {}\n");
        }
        for ($i = 1; $i <= 9; $i++) {
            freshDir($r, "/var/lib/mysql/db{$i}");
        }
        freshAnswer($box, 'redis-cli', "# Keyspace\r\ndb0:keys=200,expires=3,avg_ttl=0\r\ndb1:keys=4,expires=0,avg_ttl=0\r\n");

        $run = freshPreflight($box);

        expectStoppedUnchanged($run);
        $findings = [
            'nginx with 12 sites (s01.example.com, s02.example.com, s03.example.com and 9 more)',
            'MariaDB with 9 databases (db1, db2, db3 and 6 more)',
            'Redis with 204 keys',
        ];
        $reason = "This server isn't fresh, so the install stopped. Found: ".implode('; ', $findings)
            .'. Nothing on this server was changed. Use a fresh Ubuntu server, or run again with --force to install here anyway.';

        expect(substr_count($run['out'], 'Install stopped'))->toBe(1)
            ->and($run['out'])->toContain($reason)
            // Said once, in the sentence: not again underneath it.
            ->and(substr_count($run['out'], 'Nothing on this server was changed.'))->toBe(1);

        $report = freshFailedReport($run);
        expect($report)->toMatchArray(['state' => 'failed', 'code' => 'not_fresh', 'findings' => $findings, 'reason' => $reason])
            ->and($report['log_tail'])->toBe('');
    });

    it('treats what it cannot read for certain as a warning, never a stop', function () {
        $box = freshImage();
        freshAnswer($box, 'redis-cli', "NOAUTH Authentication required.\n");
        freshAnswer($box, 'mysql', "ERROR 1045 (28000): Access denied for user 'root'@'localhost'\n", 1);
        freshAnswer($box, 'psql', null);
        freshAnswer($box, 'mongosh', "MongoServerError: command listDatabases requires authentication\n", 1);

        $run = freshPreflight($box);

        expect($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=passed')
            ->and($run['out'])->toContain('warn: Redis is installed, but its keys could not be counted')
            ->and($run['out'])->toContain("warn: MariaDB's users could not be listed without a password (its databases were checked)")
            ->and($run['out'])->toContain("warn: PostgreSQL's users could not be listed")
            ->and($run['out'])->toContain('warn: MongoDB is installed, but its databases could not be listed')
            ->and($run['log'])->toContain('fresh-server check: warning: Redis is installed');
    });

    it('still refuses a process that is not a web server on port 80, as before', function () {
        $box = freshImage();
        freshAnswer($box, 'ss', 'LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("python3",pid=900,fd=3))'."\n");

        $run = freshPreflight($box);

        expect($run['code'])->toBe(1)
            ->and($run['out'])->toContain('port 80 is already in use by python3')
            ->and(freshFailedReport($run))->not->toHaveKey('code');
    });
});

describe('a re-run', function () {
    it('skips the check when :dataset says this installer ran here', function (Closure $mark, string $why) {
        $box = freshImage();
        // A server the panel has been running on: its sites and databases are ours.
        freshPut($box['root'], '/etc/nginx/sites-enabled/customer.example.com', "server {}\n");
        freshDir($box['root'], '/var/lib/mysql/customer');
        $mark($box['root']);

        $run = freshPreflight($box);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=rerun')
            ->and($run['out'])->toContain("ok: re-run: fresh-server check skipped ({$why}")
            ->and($run['out'])->not->toContain('customer')
            ->and($run['log'])->toContain("FRESH_CHECK=rerun\n");
    })->with([
        'the state file' => [fn ($r) => freshPut($r, '/var/lib/panel-install/state', "STATE=failed\nSTEP_NO=14\n"), "this installer's state file"],
        'the panel\'s nginx site' => [fn ($r) => freshPut($r, '/etc/nginx/sites-enabled/panel.conf', "server {}\n"), "the panel's own nginx site"],
        'the panel\'s Apache site' => [fn ($r) => freshPut($r, '/etc/apache2/sites-enabled/panel.conf', "<VirtualHost *:80>\n</VirtualHost>\n"), "the panel's own Apache site"],
        'the panel\'s OpenLiteSpeed site' => [fn ($r) => freshPut($r, '/usr/local/lsws/conf/httpd_config.conf', "virtualHost panel {\n}\n"), "the panel's own OpenLiteSpeed site"],
        'the panel\'s files' => [fn ($r) => freshDir($r, '/var/www/panel/backend'), 'the panel in '],
        'the panel account' => [fn ($r) => file_put_contents($r.'/etc/passwd', "panel:x:999:999::/home/panel:/bin/bash\n", FILE_APPEND), 'the panel account'],
    ]);
});

describe('--force', function () {
    it('goes on past every finding, warning about each, and records FRESH_CHECK=forced', function () {
        $box = freshImage();
        freshPut($box['root'], '/etc/nginx/sites-enabled/shop.example.com', "server {}\n");
        freshAnswer($box, 'redis-cli', "db0:keys=5,expires=0,avg_ttl=0\r\n");

        $run = freshPreflight($box, ['FORCE' => '1'], '
            LOGIN_STATUS=1; mkdir -p "$INSTALL_STATE_DIR"; save_install_state installing');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=forced')
            ->and($run['out'])->toContain('warn: not fresh: nginx with 1 site (shop.example.com)')
            ->and($run['out'])->toContain('warn: not fresh: Redis with 5 keys')
            ->and($run['out'])->toContain('warn: --force: installing anyway')
            ->and($run['out'])->not->toContain('Install stopped')
            ->and(freshFailedReport($run))->toBeNull()
            ->and($run['log'])->toContain("FRESH_CHECK=forced\n")
            ->and($run['log'])->toContain('fresh-server check: warning: not fresh: nginx with 1 site (shop.example.com)')
            ->and($run['state'])->toContain("FRESH_CHECK=forced\n");
    });

    it('is a documented option', function () {
        $source = installerSource();

        expect($source)->toContain('--force)    FORCE=1 ;;')
            ->and($source)->toMatch('/^  --force +Install on a server that isn\'t fresh\./m');
    });
});

describe('--dry-run', function () {
    it('runs the check, prints the findings with its plan, and writes nothing', function () {
        $box = freshImage();
        freshPut($box['root'], '/etc/nginx/sites-enabled/shop.example.com', "server {}\n");
        freshDir($box['root'], '/var/lib/mysql/shop');

        $run = freshPreflight($box, ['DRY_RUN' => '1'], 'APP_USER=panel; APP_DIR=/var/www/panel; dry_run_plan');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=not_fresh')
            ->and($run['out'])->toContain('warn: not fresh: nginx with 1 site (shop.example.com)')
            ->and($run['out'])->toContain("step: Dry run — this server isn't fresh; nothing was changed")
            ->and($run['out'])->toContain("       - MariaDB with 1 database (shop)\n")
            ->and($run['out'])->toContain('add --force to install here anyway')
            ->and($run['out'])->not->toContain('ERR-TRAP')
            ->and($run['after'])->toBe($run['before'])
            ->and($run['log'])->toBeNull()
            ->and($run['reports'])->toBe([]);
    });

    it('says the checks passed on a fresh server', function () {
        $run = freshPreflight(freshImage(), ['DRY_RUN' => '1'], 'dry_run_plan');

        expect($run['out'])->toContain('PREFLIGHT-PASSED FRESH_CHECK=passed')
            ->and($run['out'])->toContain('step: Dry run — the checks passed; nothing was changed')
            ->and($run['after'])->toBe($run['before']);
    });
});

describe('preflight', function () {
    it('checks after the OS and architecture and before the log or anything else is written', function () {
        $preflight = installerFn('preflight');
        $at = fn (string $needle) => strpos($preflight, $needle);

        expect($at('fresh_server_check'))->toBeGreaterThan($at('architecture $(uname -m)'))
            ->and($at('fresh_server_check'))->toBeLessThan($at('for port in 80 443'))
            ->and($at('fresh_server_check'))->toBeLessThan($at(': >"$LOG_FILE"'));
    });

    it('sends code and findings with no other failure', function () {
        expect(installerFn('send_progress'))->toContain('if [[ -n "${FAIL_CODE:-}" ]]; then')
            ->and(installerCode())->toContain('FAIL_CODE="not_fresh"')
            ->and(substr_count(installerCode(), 'FAIL_CODE="'))->toBe(2); // the default and the one stop
    });
});
