<?php

use App\Models\PanelUpdate;
use App\Services\Panel\FrontendUnit;
use App\Services\Panel\OlsWebAdmin;
use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelPhpBinary;
use App\Services\Panel\PanelReleases;
use App\Services\Panel\ReleaseUpdateScript;
use App\Services\Panel\UpdateScript;
use App\Support\SshPort;
use Illuminate\Support\Facades\Process;

/*
 * install.sh's fixes from the installer review of 2026-11-02
 * (reports/installer-review-20261102.md), each run for real under bash: the
 * installer's own functions, pulled out of install.sh, against a temporary
 * root with the commands they call stubbed.
 *
 * Each block names the finding it guards and the symptom it had on main.
 */

/**
 * Run $script under bash with the installer's strict mode and an ERR trap that
 * says so, the way install.sh itself runs.
 *
 * @return array{code: int, out: string}
 */
function reviewBash(string $script): array
{
    $script = 'set -Eeuo pipefail
        trap '.escapeshellarg('echo "ERR-TRAP line $LINENO: $BASH_COMMAND"').' ERR
        BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
        say() { printf "%s\n" "$*"; }
        ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; warn() { echo "warn: $*"; }
        '.$script;

    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** A scratch directory, removed after the test. */
function reviewDir(): string
{
    $dir = sys_get_temp_dir().'/installer-review-'.bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($dir)));

    return $dir;
}

describe('R1: the failure report after the admin password is unset', function () {
    /*
     * setup_backend unsets PANEL_ADMIN_PASSWORD once the admin exists, and
     * redact_log named it without a default. Under `set -u` every failure
     * after that — the frontend build running out of memory is the usual one
     * — printed "PANEL_ADMIN_PASSWORD: unbound variable" instead of the log
     * tail and the "What to do" advice, then a second, misleading box.
     */
    function failureAfterAdminCreated(string $unset): array
    {
        $dir = reviewDir();

        return reviewBash(installerFunctions('redact_log', 'log_tail', 'report_failure', 'save_install_state', 'send_progress', 'json_str', 'json_text', 'one_line', 'report_log_tail').'
            progress_stop() { :; }
            die() { progress_stop 2>/dev/null || true; report_failure "$*"; exit 1; }
            STEP_NO=17; STEP_TOTAL=20; CURRENT_STEP="Building the web interface"; LOGIN_STATUS=0; INSTALL_STARTED=1700000000
            REDACT=(); PROGRESS_READY=0
            PANEL_ADMIN_PASSWORD="hunter2-admin"; CENTRAL_TOKEN="central-secret-token"
            INSTALL_STATE_DIR='.escapeshellarg($dir.'/state').'
            LOG_FILE='.escapeshellarg($dir.'/install.log').'
            printf "%s\n" "token was central-secret-token" "FATAL ERROR: Reached heap limit" >"$LOG_FILE"
            # What setup_backend does once the admin account exists.
            PANEL_ADMIN_PASSWORD=""
            '.$unset.'
            die "npm run build failed (exit 137)"');
    }

    it('still prints the log tail and the advice', function () {
        $run = failureAfterAdminCreated('unset PANEL_ADMIN_PASSWORD');

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->not->toContain('unbound variable')
            ->and($run['out'])->not->toContain('ERR-TRAP')
            ->and($run['out'])->toContain('npm run build failed (exit 137)')
            ->and($run['out'])->toContain('Last lines of the log:')
            ->and($run['out'])->toContain('FATAL ERROR: Reached heap limit')
            ->and($run['out'])->toContain('What to do:');
    });

    it('still redacts the Central token', function () {
        $run = failureAfterAdminCreated('unset PANEL_ADMIN_PASSWORD');

        expect($run['out'])->toContain('token was [redacted]')
            ->and($run['out'])->not->toContain('central-secret-token');
    });

    it('survives the Central token being unset too', function () {
        $run = failureAfterAdminCreated('unset PANEL_ADMIN_PASSWORD CENTRAL_TOKEN');

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->not->toContain('unbound variable')
            ->and($run['out'])->toContain('What to do:');
    });
});

/**
 * $source with its absolute paths moved under $root, so a function that writes
 * /etc/... can be run for real by a user who cannot.
 *
 * @param  array<int, string>  $prefixes
 */
function reviewRooted(string $source, string $root, array $prefixes): string
{
    foreach ($prefixes as $prefix) {
        $source = str_replace($prefix, $root.$prefix, $source);
    }

    return $source;
}

describe('R4: OpenLiteSpeed reloads after its certificate renews', function () {
    /*
     * certbot --nginx and --apache reload their server on renewal; certbot
     * certonly --webroot, the only way to issue on OpenLiteSpeed, has no
     * installer and reloads nothing. The panel's own certificate renewed on
     * disk while lshttpd went on serving the old one from memory, and the
     * panel showed an expired certificate about 30 days after the renewal.
     */
    function olsTls(bool $issued): array
    {
        $root = reviewDir();
        $run = reviewBash(reviewRooted(installerFn('configure_tls_ols'), $root, ['/etc/letsencrypt/', '/etc/ssl/']).'
            run() { "$@"; }
            certbot_issue() { '.($issued ? 'return 0' : 'return 1').'; }
            install_ols_certificate() { echo "install_ols_certificate $3"; }
            openssl() { :; }
            APP_DIR=/var/www/panel; PANEL_SLUG=panel; PANEL_HOST=panel.example.com; API_HOST=panel.example.com
            SINGLE_HOST=1; ADMIN_EMAIL=""; DOMAIN=panel.example.com; LOG_FILE=/dev/null
            configure_tls_ols
            echo "TLS_STATE=$TLS_STATE"');

        return $run + ['hook' => $root.'/etc/letsencrypt/renewal-hooks/deploy/sv-oss-reload'];
    }

    it('installs a deploy hook that restarts OpenLiteSpeed', function () {
        $run = olsTls(issued: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('TLS_STATE=letsencrypt')
            ->and($run['hook'])->toBeFile();

        expect(file_get_contents($run['hook']))->toContain("\n/usr/local/lsws/bin/lswsctrl restart\n")
            ->and(fileperms($run['hook']) & 0777)->toBe(0755);
    });

    it('writes the same file the panel writes, so neither undoes the other', function () {
        // CertbotClient::ensureRenewalHook rewrites this file daily once the
        // server renews anything; a different name would mean two reloads, a
        // different body would flap.
        $run = olsTls(issued: true);

        $reload = implode(' ', (array) config('server.web_server_drivers.openlitespeed.reload_command'));
        $panel = <<<SH
        #!/bin/sh
        # Managed by the panel. Reloads the web server after certbot renews, so
        # the new certificate is actually served rather than sitting on disk.
        {$reload}
        SH;

        expect(file_get_contents($run['hook']))->toBe($panel."\n");
    });

    it('writes no hook for a self-signed certificate', function () {
        $run = olsTls(issued: false);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('TLS_STATE=self-signed')
            ->and($run['hook'])->not->toBeFile();
    });
});

describe('S1: the web interface cannot use the panel account\'s sudo', function () {
    /*
     * The frontend unit runs as APP_USER, whose sudoers grant is
     * root-equivalent (tee, sh, chown … see configure_sudoers), and nothing
     * stopped its processes from using it: on a real OpenLiteSpeed install
     * (2026-11-02) `systemd-run -p User=panel sudo -n id -u` printed 0. A
     * code-execution bug in Next or any of its dependencies was one command
     * from root. The interface never needs sudo.
     */
    function installedUnits(): array
    {
        $root = reviewDir();
        @mkdir($root.'/etc/systemd/system', 0777, true);
        @mkdir($root.'/etc/cron.d', 0777, true);

        $run = reviewBash(reviewRooted(installerFn('install_services'), $root, ['/etc/systemd/', '/etc/cron.d/']).'
            run() { :; }
            step() { :; }
            keep_queue_from_needrestart() { :; }
            APP_DIR=/var/www/panel; APP_USER=panel; PANEL_SLUG=panel; UPDATE_STATE_DIR='.escapeshellarg($root.'/update').'
            NODE_BIN=/usr/local/bin/node; FRONTEND_PORT=3100; PANEL_PHP_BIN=/usr/bin/php8.4
            install_services');

        expect($run['code'])->toBe(0, $run['out']);

        return [
            'frontend' => (string) file_get_contents($root.'/etc/systemd/system/panel-frontend.service'),
            'queue' => (string) file_get_contents($root.'/etc/systemd/system/panel-queue.service'),
        ];
    }

    it('runs the interface with no new privileges', function () {
        expect(installedUnits()['frontend'])->toMatch('/^\[Service\]\n(?:.*\n)*?NoNewPrivileges=yes$/m');
    });

    it('leaves the queue worker able to use sudo — it has to', function () {
        // Every server operation the panel queues escalates through the grant.
        expect(installedUnits()['queue'])->not->toContain('NoNewPrivileges');
    });

    it('writes the line exactly as the panel looks for it on existing servers', function () {
        expect(installedUnits()['frontend'])->toContain("\n".FrontendUnit::NO_NEW_PRIVILEGES."\n");
    });
});

describe('S2: the sudoers grant is replaced only by a validated one', function () {
    /*
     * configure_sudoers redirected the render straight into
     * /etc/sudoers.d/<slug>. On a re-run the `>` emptied the working grant
     * before anything was rendered, and a render or visudo failure then
     * removed the file: the panel had no privileges at all until a later run
     * succeeded. The new file was also in sudo's directory, world-readable
     * and unvalidated, until the chmod and visudo after it.
     */
    function sudoersRun(string $render, int $visudo = 0): array
    {
        $root = reviewDir();
        @mkdir($root.'/etc/sudoers.d', 0777, true);
        @mkdir($root.'/etc/tmpfiles.d', 0777, true);
        file_put_contents($root.'/etc/sudoers.d/panel', "panel ALL=(root) NOPASSWD: /usr/bin/old\n");
        // Writable by the test's user, as any file is to root: the installer
        // runs as root, and a 0440 grant would stop only this test.
        chmod($root.'/etc/sudoers.d/panel', 0640);

        $run = reviewBash(reviewRooted(installerFn('configure_sudoers'), $root, ['/etc/sudoers.d/', '/etc/tmpfiles.d/']).'
            run() { :; }
            step() { :; }
            die() { echo "DIE: $*"; exit 1; }
            # The render: install.sh runs artisan panel:sudoers --print through sudo -u.
            sudo() { '.$render.'; }
            visudo() { [[ "$1" == -cqf && -f "$2" ]] || { echo "visudo called with $*"; return 2; }
                echo "visudo checked $(stat -c %a "$2") $2"; return '.$visudo.'; }
            APP_DIR=/var/www/panel; APP_USER=panel; PANEL_SLUG=panel; PANEL_PHP_BIN=/usr/bin/php8.4
            LOG_FILE=/dev/stdout
            configure_sudoers');

        $grant = $root.'/etc/sudoers.d/panel';

        return $run + [
            'grant' => is_file($grant) ? (string) file_get_contents($grant) : null,
            'mode' => is_file($grant) ? fileperms($grant) & 0777 : null,
            'others' => array_values(array_diff(scandir($root.'/etc/sudoers.d'), ['.', '..', 'panel'])),
        ];
    }

    it('keeps the working grant when the render fails', function () {
        $run = sudoersRun('printf "panel ALL=(root) NOPASSWD: /usr/bin/ha"; return 1');

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: could not render the sudoers grant')
            ->and($run['grant'])->toBe("panel ALL=(root) NOPASSWD: /usr/bin/old\n")
            ->and($run['others'])->toBe([]);
    });

    it('keeps the working grant when the new one does not validate', function () {
        $run = sudoersRun('printf "panel ALL=(root NOPASSWD /usr/bin/new\n"', visudo: 1);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: the generated sudoers file did not validate')
            ->and($run['grant'])->toBe("panel ALL=(root) NOPASSWD: /usr/bin/old\n")
            ->and($run['others'])->toBe([]);
    });

    it('validates the new grant under a name sudo ignores, 0440, then moves it into place', function () {
        $run = sudoersRun('printf "panel ALL=(root) NOPASSWD: /usr/bin/new\n"');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['grant'])->toBe("panel ALL=(root) NOPASSWD: /usr/bin/new\n")
            ->and($run['mode'])->toBe(0440)
            ->and($run['others'])->toBe([])
            // sudo skips names in /etc/sudoers.d that contain a dot.
            ->and($run['out'])->toMatch('#visudo checked 440 \S+/etc/sudoers\.d/[^/\s]*\.[^/\s]*#');
    });
});

describe('S4: values from the command line and environment are checked first', function () {
    /*
     * --domain, --branch, --repo and --email were used as given, and so were
     * PANEL_SLUG, APP_USER and APP_DIR from the environment. A pasted
     * `https://panel.example.com/` went into every vhost's server_name and
     * failed late, at certbot; a value with `;` or `}` rewrote the nginx
     * config around it; a `/` broke the Apache fallback's sed.
     */
    function inputsRun(array $args = [], array $env = []): array
    {
        $env += ['PANEL_SLUG' => 'panel', 'APP_USER' => 'panel', 'APP_DIR' => '/var/www/panel'];
        $vars = ['DOMAIN' => '', 'ADMIN_EMAIL' => '', 'REPO_BRANCH' => 'main', 'REPO_URL' => 'https://github.com/DipaliRadadiya/open-source-sa.git'];

        $set = '';
        foreach ($args + $vars + $env as $name => $value) {
            $set .= $name.'='.escapeshellarg($value)."\n";
        }

        return reviewBash(installerFn('validate_inputs').'
            die() { echo "DIE: $*"; exit 1; }
            '.$set.'
            validate_inputs
            echo "ACCEPTED DOMAIN=$DOMAIN"');
    }

    it('accepts what a real install passes', function (array $args, array $env = []) {
        $run = inputsRun($args, $env);

        expect($run['code'])->toBe(0, $run['out'])->and($run['out'])->toContain('ACCEPTED');
    })->with([
        'defaults' => [[]],
        'a domain' => [['DOMAIN' => 'panel.example.com']],
        'a deep domain, digits and hyphens' => [['DOMAIN' => 'a-1.b2.panel.example.co.uk']],
        'an IDN in punycode' => [['DOMAIN' => 'xn--bcher-kva.xn--p1ai']],
        'an email' => [['ADMIN_EMAIL' => 'ops+le@example.com']],
        'a branch with a slash' => [['REPO_BRANCH' => 'fix/installer-review-dipali']],
        'a release-style branch' => [['REPO_BRANCH' => 'release/v8.0.1']],
        'an ssh repository' => [['REPO_URL' => 'git@github.com:someone/fork.git']],
        'a local mirror' => [['REPO_URL' => '/srv/mirror/panel.git']],
        'a custom slug, user and directory' => [[], ['PANEL_SLUG' => 'sv-panel2', 'APP_USER' => 'sv_panel', 'APP_DIR' => '/opt/sv-panel2']],
    ]);

    it('lower-cases the domain rather than refusing it', function () {
        expect(inputsRun(['DOMAIN' => 'Panel.Example.COM'])['out'])->toContain('ACCEPTED DOMAIN=panel.example.com');
    });

    it('refuses what would break a config or a command', function (array $args, array $env, string $message) {
        $run = inputsRun($args, $env);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->not->toContain('ACCEPTED')
            ->and($run['out'])->toContain('DIE: '.$message)
            // report_failure's cue that this is not a failed install: without
            // it, a re-run's refusal showed the last install's log as its own.
            ->and($run['out'])->toContain('Nothing has been changed on this server.');
    })->with([
        'a pasted URL' => [['DOMAIN' => 'https://panel.example.com/'], [], '--domain must be a host name'],
        'config injection' => [['DOMAIN' => 'x.example.com; } server { listen 80 default_server; root /;'], [], '--domain must be a host name'],
        'a bare name' => [['DOMAIN' => 'localhost'], [], '--domain must be a host name'],
        'a trailing hyphen' => [['DOMAIN' => 'panel-.example.com'], [], '--domain must be a host name'],
        'an email with a space' => [['ADMIN_EMAIL' => 'ops @example.com'], [], '--email must be an email address'],
        'an email that is an option' => [['ADMIN_EMAIL' => '--staging@x.io'], [], '--email must be an email address'],
        'a branch that is an option' => [['REPO_BRANCH' => '--upload-pack=touch /tmp/x'], [], '--branch must be a branch name'],
        'a branch with ..' => [['REPO_BRANCH' => 'main/../x'], [], '--branch must be a branch name'],
        'a repo that is an option' => [['REPO_URL' => '--upload-pack=touch /tmp/x'], [], '--repo must be'],
        'a repo with a space' => [['REPO_URL' => 'https://example.com/a b.git'], [], '--repo must be'],
        'a slug with a slash' => [[], ['PANEL_SLUG' => '../etc'], 'PANEL_SLUG must be'],
        'a slug with a space' => [[], ['PANEL_SLUG' => 'my panel'], 'PANEL_SLUG must be'],
        'an upper-case user' => [[], ['APP_USER' => 'Panel'], 'APP_USER must be'],
        'a relative directory' => [[], ['APP_DIR' => 'var/www/panel'], 'APP_DIR must be'],
        'the root directory' => [[], ['APP_DIR' => '/'], 'APP_DIR must be'],
        'a top-level directory' => [[], ['APP_DIR' => '/var'], 'APP_DIR must be'],
        'a directory with ..' => [[], ['APP_DIR' => '/var/www/../../etc'], 'APP_DIR must be'],
        'a directory with a space' => [[], ['APP_DIR' => '/var/www/my panel'], 'APP_DIR must be'],
    ]);

    it('does not show an earlier install\'s log as the reason', function () {
        // Found on a real re-run (2026-11-02): the refusal printed the last
        // install's log tail and "What to do" under "Install stopped".
        $dir = reviewDir();
        $run = reviewBash(installerFunctions('validate_inputs', 'redact_log', 'log_tail', 'report_failure', 'save_install_state', 'send_progress', 'json_str', 'json_text', 'one_line', 'report_log_tail').'
            die() { report_failure "$*"; exit 1; }
            STEP_NO=0; STEP_TOTAL=0; CURRENT_STEP="starting up"; LOGIN_STATUS=0; INSTALL_STARTED=1700000000
            REDACT=(); PANEL_ADMIN_PASSWORD=""; CENTRAL_TOKEN=""; PROGRESS_READY=0
            INSTALL_STATE_DIR='.escapeshellarg($dir.'/state').'
            LOG_FILE='.escapeshellarg($dir.'/install.log').'
            echo "Site roots: 0 locked." >"$LOG_FILE"
            DOMAIN=https://panel.example.com/; ADMIN_EMAIL=""; REPO_BRANCH=main; REPO_URL=https://example.com/p.git
            PANEL_SLUG=panel; APP_USER=panel; APP_DIR=/var/www/panel
            validate_inputs');

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('--domain must be a host name')
            ->and($run['out'])->not->toContain('Last lines of the log')
            ->and($run['out'])->not->toContain('Site roots')
            ->and($run['out'])->not->toContain('What to do');
    });

    it('checks them before anything else, even before asking for root', function () {
        // The whole installer, as this (non-root) user: on main a bad --domain
        // got as far as "run this as root", proof that nothing had looked at it.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('runs the real installer; only safe as a non-root user');
        }

        $dir = reviewDir();
        $env = 'INSTALL_STATE_DIR='.escapeshellarg($dir.'/state').' UPDATE_STATE_DIR='.escapeshellarg($dir.'/update');
        exec($env.' bash '.escapeshellarg(base_path('../install.sh')).' --dry-run '.escapeshellarg('--domain=https://panel.example.com/').' 2>&1', $out, $code);
        $out = implode("\n", $out);

        expect($code)->toBe(1, $out)
            ->and($out)->toContain('--domain must be a host name')
            ->and($out)->not->toContain('run this as root');
    });
});

it('S4: ends git clone\'s options before the repository and directory', function () {
    expect(installerCode())->toMatch('/git clone [^\n]*-- "\$REPO_URL" "\$APP_DIR"/');
});

describe('R2: a directory in the panel\'s place is moved aside, never deleted', function () {
    /*
     * fetch_source found APP_DIR without a .git, called it "a leftover from
     * an interrupted attempt" and ran rm -rf on it. With the fresh-server
     * guard taking any directory there for a re-run, that was somebody
     * else's application. Even a real leftover costs nothing to keep.
     */
    it('keeps what was there, beside the new checkout', function () {
        $root = reviewDir();
        $app = $root.'/var/www/panel';
        mkdir($app, 0777, true);
        file_put_contents($app.'/customer.txt', 'customer data');

        $run = reviewBash(installerFn('fetch_source_body').'
            run() { "$@"; }
            progress_start() { :; }; progress_stop() { :; }
            assert_panel_knows_stack() { :; }
            chown() { :; }; sudo() { :; }
            git() { case "$1" in clone) mkdir -p "${@: -1}/.git" ;; esac; }
            APP_DIR='.escapeshellarg($app).'; APP_USER=panel; REPO_BRANCH=main; REPO_URL=https://example.com/panel.git
            LOG_FILE=/dev/null
            fetch_source_body');

        $aside = glob($app.'.old-*');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($app.'/.git')->toBeDirectory()
            ->and($aside)->toHaveCount(1)
            ->and(file_get_contents($aside[0].'/customer.txt'))->toBe('customer data')
            ->and($run['out'])->toContain('moved to '.$aside[0]);
    });
});

/**
 * A function out of install.sh whose body has a `}` line of its own (a
 * heredoc), which installerFn would stop at: everything up to the function
 * that follows it in the file.
 */
function installerFnBefore(string $name, string $next): string
{
    $source = installerSource();
    $start = strpos($source, "\n{$name}() {\n");
    $end = strpos($source, "\n{$next}() {\n", (int) $start);

    expect($start)->toBeInt("install.sh has no {$name}()")->and($end)->toBeInt("install.sh has no {$next}() after {$name}()");

    $chunk = substr($source, $start + 1, $end - $start);

    // Up to and including the last top-level `}` before the next function.
    return substr($chunk, 0, (int) strrpos($chunk, "\n}\n") + 3);
}

describe('R3: a TLS config OpenLiteSpeed rejects is taken back out', function () {
    /*
     * install_ols_certificate appended vhssl{} to the vhosts and a Defaultssl
     * listener to httpd_config.conf, and when `openlitespeed -t` rejected the
     * result it only set SCHEME=http. The running server was fine; at the
     * next restart or reboot lshttpd read the broken file and did not start,
     * taking the panel and every site with it.
     */
    function olsCertificateRun(int $test): array
    {
        $root = reviewDir();
        $conf = $root.'/httpd_config.conf';
        $vhconf = $root.'/vhconf.conf';
        file_put_contents($conf, "listener Default{\n    address                  *:80\n}\n");
        file_put_contents($vhconf, "docRoot /var/www/panel/backend/public\n");
        @mkdir($root.'/usr/local/lsws/bin', 0777, true);
        file_put_contents($root.'/usr/local/lsws/bin/openlitespeed', "#!/bin/sh\necho \"[ERROR] test said no\"\nexit {$test}\n");
        chmod($root.'/usr/local/lsws/bin/openlitespeed', 0755);

        $run = reviewBash(reviewRooted(installerFnBefore('install_ols_certificate', 'configure_tls'), $root, ['/usr/local/lsws/']).'
            run() { "$@"; }
            systemctl() { echo "systemctl $*"; }
            ols_listener_maps() { echo "  map                     panel panel.example.com"; }
            PANEL_SLUG=panel; PANEL_HOST=panel.example.com; SINGLE_HOST=1; LOG_FILE=/dev/null
            SCHEME=https; TLS_STATE=letsencrypt
            install_ols_certificate '.escapeshellarg($conf).' '.escapeshellarg($vhconf).' /etc/letsencrypt/live/x/fullchain.pem /etc/letsencrypt/live/x/privkey.pem
            echo "SCHEME=$SCHEME TLS_STATE=$TLS_STATE"');

        return $run + [
            'conf' => (string) file_get_contents($conf),
            'vhconf' => (string) file_get_contents($vhconf),
            'left' => array_values(array_diff(scandir($root), ['.', '..', 'usr', 'httpd_config.conf', 'vhconf.conf'])),
        ];
    }

    it('restores both files exactly when the test fails', function () {
        $run = olsCertificateRun(test: 2);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('SCHEME=http TLS_STATE=none')
            ->and($run['out'])->not->toContain('systemctl restart')
            ->and($run['conf'])->toBe("listener Default{\n    address                  *:80\n}\n")
            ->and($run['vhconf'])->toBe("docRoot /var/www/panel/backend/public\n")
            ->and($run['left'])->toBe([]);
    });

    it('keeps the TLS config, and no backup, when the test passes', function () {
        $run = olsCertificateRun(test: 0);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('systemctl restart lshttpd')
            ->and($run['conf'])->toContain('listener Defaultssl {')
            ->and($run['vhconf'])->toContain('vhssl {')
            ->and($run['left'])->toBe([]);
    });
});

describe('R5: a re-run that gets a real certificate drops the self-signed fallback', function () {
    /*
     * When Let's Encrypt failed (common on nip.io) the installer wrote a
     * self-signed site, <slug>-tls, beside the port-80 one and told the user
     * to re-run later. Nothing removed it on that re-run: after certbot
     * succeeded two server blocks answered on 443 for the same name, and the
     * stale self-signed one could be the one served.
     */
    function tlsRerun(string $webServer, bool $issued): array
    {
        $root = reviewDir();
        foreach (['/etc/nginx/sites-available', '/etc/nginx/sites-enabled', '/etc/apache2/sites-available', '/etc/apache2/sites-enabled'] as $dir) {
            @mkdir($root.$dir, 0777, true);
        }
        // What the earlier run's fallback left.
        $stale = $webServer === 'nginx'
            ? ['/etc/nginx/sites-available/panel-tls.conf', '/etc/nginx/sites-enabled/panel-tls.conf']
            : ['/etc/apache2/sites-available/panel-tls.conf', '/etc/apache2/sites-enabled/panel-tls.conf'];
        foreach ($stale as $path) {
            file_put_contents($root.$path, "# Self-signed fallback, written by the Control panel installer.\n");
        }

        $run = reviewBash(reviewRooted(installerFnBefore('configure_tls', 'certbot_issue'), $root, ['/etc/nginx/', '/etc/apache2/', '/etc/ssl/']).'
            run() { "$@"; }
            step() { :; }
            certbot_issue() { echo "certbot $*"; '.($issued ? 'return 0' : 'return 1').'; }
            a2dissite() { rm -f '.escapeshellarg($root).'/etc/apache2/sites-enabled/"$1".conf; echo "a2dissite $1"; }
            openssl() { :; }; self_signed_apache() { echo "self_signed_apache"; TLS_STATE=self-signed; }
            nginx() { return 0; }; systemctl() { :; }
            WANT_SSL=1; WEB_SERVER='.$webServer.'; PANEL_SLUG=panel; PANEL_HOST=panel.example.com; API_HOST=panel.example.com
            SINGLE_HOST=1; ADMIN_EMAIL=""; DOMAIN=""; APP_DIR=/var/www/panel; LOG_FILE=/dev/null
            configure_tls
            echo "TLS_STATE=$TLS_STATE"');

        return $run + ['left' => array_values(array_filter($stale, fn ($p) => file_exists($root.$p)))];
    }

    it('removes the nginx fallback before certbot runs', function () {
        $run = tlsRerun('nginx', issued: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('TLS_STATE=letsencrypt')
            ->and($run['left'])->toBe([]);
    });

    it('disables and removes the Apache fallback before certbot runs', function () {
        $run = tlsRerun('apache', issued: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('a2dissite panel-tls')
            ->and($run['out'])->toContain('TLS_STATE=letsencrypt')
            ->and($run['left'])->toBe([]);
    });

    it('still falls back to self-signed when certbot fails again', function () {
        $run = tlsRerun('apache', issued: false);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('self_signed_apache');
    });
});

describe('R6: a rejected OpenLiteSpeed config on a re-run restores this run\'s copy', function () {
    /*
     * configure_ols saved httpd_config.conf as .preinstall on the first run
     * only, and restored THAT copy whenever `openlitespeed -t` rejected the
     * generated config. On a re-run the live file also holds every site the
     * panel registered since (OlsSharedConfig writes them there), so the
     * restore deleted those sites from the web server.
     */
    function olsRerun(): array
    {
        $root = reviewDir();
        $conf = $root.'/usr/local/lsws/conf/httpd_config.conf';
        @mkdir(dirname($conf), 0777, true);
        @mkdir($root.'/usr/local/lsws/bin', 0777, true);
        @mkdir($root.'/etc/tmpfiles.d', 0777, true);
        // As OpenLiteSpeed shipped it, saved by the first run.
        file_put_contents($conf.'.preinstall', "listener Default{\n}\n");
        // Today: the panel, and a site created through it since.
        file_put_contents($conf, "listener Default{\n}\nvirtualHost panel {\n}\nvirtualHost customer-shop {\n}\n");
        // Rejects the generated config, accepts the one it is restored to.
        file_put_contents($root.'/usr/local/lsws/bin/openlitespeed', "#!/bin/sh\nif grep -q BROKEN {$conf}; then echo '[ERROR] no'; exit 2; fi\nexit 0\n");
        chmod($root.'/usr/local/lsws/bin/openlitespeed', 0755);

        $run = reviewBash(reviewRooted(installerFnBefore('configure_ols', 'ensure_ols_context_paths'), $root, ['/usr/local/lsws/', '/etc/', '/run/']).'
            run() { case "$1" in mkdir|cp) "$@" ;; *) : ;; esac; }
            step() { :; }
            die() { echo "DIE: $*"; exit 1; }
            install_ols_packages() { :; }; ensure_ols_context_paths() { :; }; ensure_ols_dynamic_response_limit() { :; }
            write_ols_vhost() { :; }; write_ols_api_vhost() { :; }; harden_ols_webadmin() { :; }
            systemctl() { echo "systemctl $*"; }; systemd-tmpfiles() { :; }
            register_ols_panel_vhost() { echo "BROKEN" >>"$1"; }
            PANEL_SLUG=panel; APP_USER=panel; OLS_USER=nobody; APP_DIR=/var/www/panel; SINGLE_HOST=1
            PANEL_HOST=panel.example.com; LOG_FILE=/dev/null
            configure_ols');

        return $run + ['conf' => (string) file_get_contents($conf)];
    }

    it('keeps the sites registered since the first install', function () {
        $run = olsRerun();

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: OpenLiteSpeed rejected the generated config')
            ->and($run['conf'])->toContain('virtualHost customer-shop')
            ->and($run['conf'])->not->toContain('BROKEN');
    });
});

describe('S3: what is run or trusted as root is checked first', function () {
    /*
     * Composer's installer and fnm's install script were downloaded to fixed
     * names in /tmp and run as root; the sury.org and Docker apt keys were
     * installed as fetched. Nothing compared any of them with anything, so a
     * compromised upstream or CDN was root on every new server. The
     * LiteSpeed keys were already pinned; these now are too.
     */
    $download = '
        run() { "$@"; }
        progress_start() { :; }; progress_stop() { :; }
        die() { echo "DIE: $*"; exit 1; }
        DRY_RUN=0; LOG_FILE=/dev/null
    ';

    /** gpg --show-keys --with-colons for keys with these primary fingerprints. */
    function colonsFor(string ...$fprs): string
    {
        $out = '';
        foreach ($fprs as $fpr) {
            $out .= 'pub:-:4096:1:'.substr($fpr, -16).":1550000000:::-:::scSC::::::23::0:\nfpr:::::::::{$fpr}:\n";
            $out .= "sub:-:4096:1:AAAAAAAAAAAAAAAA:1550000000::::::e::::::23:\nfpr:::::::::0000000000000000000000000000AAAAAAAAAAAA:\n";
        }

        return $out;
    }

    function aptKeyRun(string $download, string $colons): array
    {
        $root = reviewDir();
        file_put_contents($root.'/colons', $colons);

        $run = reviewBash(installerFunctions('download_tmp', 'fetch_apt_key').$download.'
            run_progress() { shift; "$@"; }
            curl() { while [[ "$1" != -o ]]; do shift; done; echo "key bytes" >"$2"; }
            gpg() { cat '.escapeshellarg($root.'/colons').'; }
            RUN_DIR='.escapeshellarg($root).'; DIM=""; RESET=""
            fetch_apt_key "Docker repository" https://download.docker.com/linux/ubuntu/gpg 9DC858229FC7DD38854AE2D88D81803C0EBFCD88 '.escapeshellarg($root.'/docker.asc').'
            echo INSTALLED');

        return $run + ['installed' => is_file($root.'/docker.asc'), 'left' => count(glob($root.'/download.*'))];
    }

    it('installs the apt key that is the expected one', function () use ($download) {
        $run = aptKeyRun($download, colonsFor('9DC858229FC7DD38854AE2D88D81803C0EBFCD88'));

        expect($run['code'])->toBe(0, $run['out'])->and($run['installed'])->toBeTrue()->and($run['left'])->toBe(0);
    });

    it('refuses an apt key with another fingerprint, or with a second key in the file', function (array $fprs) use ($download) {
        $run = aptKeyRun($download, colonsFor(...$fprs));

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: the Docker repository signing key from https://download.docker.com/linux/ubuntu/gpg is not the expected one')
            ->and($run['installed'])->toBeFalse()
            ->and($run['left'])->toBe(0);
    })->with([
        'another key' => [['1111111111111111111111111111111111111111']],
        'the key and another' => [['9DC858229FC7DD38854AE2D88D81803C0EBFCD88', '1111111111111111111111111111111111111111']],
        'nothing' => [[]],
    ]);

    it('pins the fingerprints sury.org and Docker publish', function () {
        expect(installerSource())->toContain('SURY_KEY_FPR="15058500A0235D97F5D10063B188E2B695BD4743"')
            ->and(installerSource())->toContain('DOCKER_KEY_FPR="9DC858229FC7DD38854AE2D88D81803C0EBFCD88"')
            ->and(installerCode())->not->toMatch('#curl [^\n]*-o "\$keyring" https://(packages\.sury\.org|download\.docker\.com)#');
    });

    function composerRun(string $download, bool $tampered): array
    {
        $root = reviewDir();
        $installer = "<?php echo 'composer installer';\n";
        $sig = hash('sha384', $installer);
        file_put_contents($root.'/served', $tampered ? $installer."// and something else\n" : $installer);

        $run = reviewBash(installerFunctions('download_tmp', 'install_composer_binary').$download.'
            curl() { local out url; while (( $# )); do case "$1" in -o) out="$2"; shift ;; https://*) url="$1" ;; esac; shift; done
                case "$url" in *installer.sig) echo '.$sig.' >"$out" ;; *) cp '.escapeshellarg($root.'/served').' "$out" ;; esac; }
            PANEL_PHP_BIN=fakephp; fakephp() { echo "RAN THE INSTALLER $1"; }
            RUN_DIR='.escapeshellarg($root).'
            install_composer_binary');

        return $run + ['left' => count(glob($root.'/download.*'))];
    }

    it('runs a Composer installer that matches Composer\'s published checksum', function () use ($download) {
        $run = composerRun($download, tampered: false);

        expect($run['code'])->toBe(0, $run['out'])->and($run['out'])->toContain('RAN THE INSTALLER')->and($run['left'])->toBe(0);
    });

    it('refuses a Composer installer that does not', function () use ($download) {
        $run = composerRun($download, tampered: true);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain("DIE: Composer's installer does not match the checksum")
            ->and($run['out'])->not->toContain('RAN THE INSTALLER')
            ->and($run['left'])->toBe(0);
    });

    function fnmRun(string $download, bool $tampered): array
    {
        $root = reviewDir();
        $zip = $root.'/fnm-linux.zip';
        file_put_contents($root.'/fnm', "#!/bin/sh\necho fnm\n");
        exec('cd '.escapeshellarg($root).' && zip -q fnm-linux.zip fnm && rm fnm');
        $sha = hash_file('sha256', $zip);
        if ($tampered) {
            file_put_contents($zip, 'x', FILE_APPEND);
        }
        mkdir($root.'/fnm-dir');

        $run = reviewBash(installerFunctions('download_tmp', 'install_fnm_binary').$download.'
            curl() { local out; while (( $# )); do case "$1" in -o) out="$2"; shift ;; esac; shift; done; cp '.escapeshellarg($zip).' "$out"; }
            uname() { echo x86_64; }
            FNM_VERSION=1.39.0; FNM_SHA256_X86_64='.$sha.'; FNM_SHA256_AARCH64=none
            FNM_DIR='.escapeshellarg($root.'/fnm-dir').'; RUN_DIR='.escapeshellarg($root).'
            install_fnm_binary');

        return $run + ['installed' => is_file($root.'/fnm-dir/fnm'), 'left' => count(glob($root.'/download.*'))];
    }

    it('installs the pinned fnm binary that matches its checksum', function () use ($download) {
        $run = fnmRun($download, tampered: false);

        expect($run['code'])->toBe(0, $run['out'])->and($run['installed'])->toBeTrue()->and($run['left'])->toBe(0);
    });

    it('refuses an fnm download that does not', function () use ($download) {
        $run = fnmRun($download, tampered: true);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: fnm 1.39.0 (fnm-linux.zip) does not match its pinned checksum')
            ->and($run['installed'])->toBeFalse()
            ->and($run['left'])->toBe(0);
    });

    it('runs nothing out of a fixed name in /tmp any more', function () {
        expect(installerCode())->not->toContain('/tmp/composer-setup.php')
            ->and(installerCode())->not->toContain('/tmp/fnm-install.sh')
            ->and(installerCode())->not->toContain('fnm.vercel.app');
    });
});

describe('R7: OpenLiteSpeed stops at once if the panel\'s own PHP cannot be installed', function () {
    /*
     * install_ols_packages installed lsphp and every extension in one apt
     * call and only warned when it failed. Since the install-speed work this
     * runs only when the one big transaction has already failed, but then a
     * missing name still left the panel's own PHP (lsphp on this stack) or
     * its curl/intl/sqlite3/redis extensions uninstalled, and the install
     * went on to fail later, in composer or artisan, far from the cause.
     */
    function olsPackagesRun(string $missing): array
    {
        return reviewBash(installerFn('install_ols_packages').'
            progress_start() { :; }; progress_stop() { :; }
            die() { echo "DIE: $*"; exit 1; }
            apt-get() { for a in "$@"; do [[ "$a" == '.escapeshellarg($missing).' ]] && { echo "E: Unable to locate package $a"; return 100; }; done; echo "apt-get $*"; }
            PHP_VERSION=8.4; OLS_PACKAGES_DONE=0; LOG_FILE=/dev/stdout
            install_ols_packages
            echo "CONTINUED"');
    }

    it('stops when an extension the panel itself runs on is missing', function (string $missing) {
        $run = olsPackagesRun($missing);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: could not install lsphp84')
            ->and($run['out'])->not->toContain('CONTINUED');
    })->with(['lsphp84', 'lsphp84-common', 'lsphp84-curl', 'lsphp84-intl', 'lsphp84-sqlite3', 'lsphp84-redis']);

    it('goes on, with a warning, when only an extension for hosted sites is missing', function (string $missing) {
        $run = olsPackagesRun($missing);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('CONTINUED')
            ->and($run['out'])->toContain('warn:')
            // The panel's own set went in regardless.
            ->and($run['out'])->toMatch('/apt-get install -y lsphp84 lsphp84-common .*lsphp84-sqlite3/');
    })->with(['lsphp84-pgsql', 'lsphp84-mysql', 'lsphp84-imap']);

    it('still lists every package for the one big transaction', function () {
        $run = reviewBash(installerFn('install_ols_packages').'
            PHP_VERSION=8.4
            install_ols_packages --list');

        expect(preg_split('/\R/', trim($run['out'])))->toEqualCanonicalizing([
            'lsphp84', 'lsphp84-common', 'lsphp84-mysql', 'lsphp84-pgsql', 'lsphp84-curl', 'lsphp84-intl',
            'lsphp84-sqlite3', 'lsphp84-redis', 'lsphp84-igbinary', 'lsphp84-opcache', 'lsphp84-imagick', 'lsphp84-imap',
        ]);
    });
});

describe('S5: OpenLiteSpeed\'s WebAdmin console listens on this server only', function () {
    /*
     * harden_ols_webadmin said ":7080 is not opened in the firewall", but
     * the installer never enables ufw, so on a stock Ubuntu box the console
     * answered on every address (`ss` showed *:7080 on a real install,
     * 2026-11-03). Now its listener is bound to 127.0.0.1.
     */
    $shipped = "accessControl {\n  allow                 ALL\n}\nlistener adminListener{\n  address               *:7080\n  secure                1\n  keyFile               \$SERVER_ROOT/admin/conf/webadmin.key\n}\n";

    function webAdminRun(?string $config): array
    {
        $dir = reviewDir();
        if ($config !== null) {
            file_put_contents($dir.'/admin_config.conf', $config);
        }

        $run = reviewBash(installerFn('harden_ols_webadmin').'
            LOG_FILE=/dev/null
            harden_ols_webadmin '.escapeshellarg($dir.'/admin_config.conf').'
            echo "CONTINUED"');

        return $run + ['config' => (string) @file_get_contents($dir.'/admin_config.conf')];
    }

    it('binds the console to 127.0.0.1 and says so', function () use ($shipped) {
        $run = webAdminRun($shipped);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['config'])->toBe(str_replace('*:7080', '127.0.0.1:7080', $shipped))
            ->and($run['out'])->toContain('ok: WebAdmin console on :7080 listens on this server only')
            ->and($run['out'])->not->toContain('not opened in the firewall');
    });

    it('binds an IPv6 or 0.0.0.0 listener too, and changes nothing else', function (string $address) use ($shipped) {
        $run = webAdminRun(str_replace('*:7080', $address, $shipped));

        expect($run['config'])->toBe(str_replace('*:7080', '127.0.0.1:7080', $shipped));
    })->with(['[ANY]:7080', '0.0.0.0:7080', '127.0.0.1:7080']);

    it('warns rather than claiming the port is closed when it cannot bind it', function () use ($shipped) {
        $moved = webAdminRun(str_replace('*:7080', '*:9090', $shipped));
        $missing = webAdminRun(null);

        expect($moved['config'])->toContain('*:9090')
            ->and($moved['out'])->toContain('warn: could not bind the WebAdmin console to 127.0.0.1')
            ->and($moved['out'])->toContain('CONTINUED')
            ->and($missing['out'])->toContain('warn: could not bind the WebAdmin console to 127.0.0.1');
    });

    it('rewrites the file the same way the panel does for existing servers', function () use ($shipped) {
        expect(OlsWebAdmin::desired($shipped))->toBe(webAdminRun($shipped)['config'])
            ->and(OlsWebAdmin::desired(str_replace('*:7080', '127.0.0.1:7080', $shipped)))->toBeNull()
            ->and(OlsWebAdmin::desired(str_replace('*:7080', '*:9090', $shipped)))->toBeNull();
    });

    it('panel:ols-webadmin rebinds an existing server and restarts OpenLiteSpeed', function () use ($shipped) {
        $dir = reviewDir();
        config(['server.web_server_drivers.openlitespeed.webadmin_config' => $dir.'/admin_config.conf']);
        file_put_contents($dir.'/admin_config.conf', $shipped);
        Process::fake();

        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('now listens on 127.0.0.1:7080')->assertSuccessful();

        expect((string) file_get_contents($dir.'/admin_config.conf'))->toContain('address               127.0.0.1:7080')
            ->and(decoct(fileperms($dir.'/admin_config.conf') & 0777))->toBe('644');
        Process::assertRan(fn ($process) => $process->command === ['/usr/local/lsws/bin/lswsctrl', 'restart']);

        // Idempotent: a second run changes nothing and restarts nothing.
        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('is up to date')->assertSuccessful();
        Process::assertRanTimes(fn ($process) => $process->command === ['/usr/local/lsws/bin/lswsctrl', 'restart'], 1);
    });

    it('panel:ols-webadmin does nothing on a server without OpenLiteSpeed', function () {
        config(['server.web_server_drivers.openlitespeed.webadmin_config' => reviewDir().'/absent.conf']);
        Process::fake();

        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('nothing to change')->assertSuccessful();
        Process::assertNothingRan();
    });

    it('reaches existing servers through both updaters, as root, without failing the update', function () {
        $update = new PanelUpdate(['id' => 1, 'from_commit' => str_repeat('a', 40)]);
        $layout = new PanelLayout('/var/www/panel/current/backend');
        $scripts = [
            app(UpdateScript::class)->render($update, '1.0.17'),
            (new ReleaseUpdateScript($layout, new PanelReleases($layout), new PanelPhpBinary))->render($update, '1.0.2'),
        ];

        foreach ($scripts as $script) {
            $line = collect(explode("\n", $script))->first(fn (string $l) => str_contains($l, 'artisan panel:ols-webadmin'));

            expect($line)->not->toBeNull()
                ->and($line)->toContain('|| echo "WARNING')
                ->and($line)->not->toContain('sudo -u');
        }

        expect(UpdateScript::STEPS)->toContain('configure_ols_webadmin')
            ->and(__('panel_update.steps.configure_ols_webadmin'))->not->toBe('panel_update.steps.configure_ols_webadmin');
    });
});

describe('R11: the firewall allows the SSH port set in sshd_config itself', function () {
    /*
     * configure_firewall read the port from sshd_config.d drop-ins only, and
     * so did the panel (SshPort). With `Port 2222` in sshd_config itself, the
     * installer allowed 22 — on a server whose ufw is already on (as on
     * TeamCompute's images), that is the one rule between the owner and a
     * lockout the day 22 is closed.
     */
    function sshFixture(?string $main, array $dropIns = []): string
    {
        $dir = reviewDir();
        mkdir($dir.'/sshd_config.d');
        if ($main !== null) {
            file_put_contents($dir.'/sshd_config', $main);
        }
        foreach ($dropIns as $name => $body) {
            file_put_contents($dir.'/sshd_config.d/'.$name, $body);
        }

        return $dir;
    }

    function installerSshPort(string $dir): string
    {
        $run = reviewBash(installerFn('find_ssh_port').'
            find_ssh_port '.escapeshellarg($dir.'/sshd_config').' '.escapeshellarg($dir.'/sshd_config.d'));

        expect($run['code'])->toBe(0, $run['out']);

        return trim($run['out']);
    }

    $stock = "Include /etc/ssh/sshd_config.d/*.conf\n\n#Port 22\n#AddressFamily any\n";
    $cases = [
        'moved in sshd_config' => [str_replace('#Port 22', "Port 2222\nPort 22", $stock), [], '2222'],
        'a drop-in wins over sshd_config' => [str_replace('#Port 22', 'Port 2222', $stock), ['00-panel.conf' => "Port 2200\n"], '2200'],
        'the first drop-in by name' => [$stock, ['60-b.conf' => "Port 2300\n", '10-a.conf' => "PasswordAuthentication no\n  port 2301\n"], '2301'],
        'nothing set' => [$stock, ['50-cloud-init.conf' => "PasswordAuthentication no\n"], '22'],
        'no sshd_config at all' => [null, [], '22'],
    ];

    it('reads the port the way sshd does', function (?string $main, array $dropIns, string $port) {
        expect(installerSshPort(sshFixture($main, $dropIns)))->toBe($port);
    })->with($cases);

    it('gives the same answer as the panel, so the rule and the record agree', function (?string $main, array $dropIns, string $port) {
        $dir = sshFixture($main, $dropIns);
        config(['server.sshd_config_dir' => $dir.'/sshd_config.d', 'server.sshd_config_file' => $dir.'/sshd_config', 'server.ssh_port' => 22]);

        expect((string) SshPort::current())->toBe($port)
            ->and(installerSshPort($dir))->toBe($port);
    })->with($cases);

    it('allows that port in ufw', function () use ($stock) {
        $dir = sshFixture(str_replace('#Port 22', 'Port 2222', $stock));

        $run = reviewBash(installerFunctions('find_ssh_port', 'configure_firewall').'
            eval "real_$(declare -f find_ssh_port)"
            find_ssh_port() { real_find_ssh_port '.escapeshellarg($dir.'/sshd_config').' '.escapeshellarg($dir.'/sshd_config.d').'; }
            step() { :; }; run() { echo "RUN $*"; }
            ufw() { echo "Status: active"; }
            APP_USER=panel; APP_DIR=/var/www/panel; PANEL_PHP_BIN=php
            configure_firewall');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('RUN ufw allow 2222/tcp')
            ->and($run['out'])->not->toContain('RUN ufw allow 22/tcp');
    });
});

describe('P1: nginx installs and starts on a host without IPv6', function () {
    /*
     * On a server booted with ipv6.disable=1 (no AF_INET6 at all), main
     * stopped at "Installing packages": Ubuntu's nginx package starts nginx
     * with its default site, which listens on [::]:80, and nginx failed with
     * "socket() [::]:80 failed (97: Address family not supported by
     * protocol)". The installer's own vhosts had the same `listen [::]`
     * lines. The sysctl disable_ipv6 switch does not reproduce it — binding
     * [::] still works there — which is why the check is /proc/net/if_inet6.
     */
    $vhost = "server {\n    listen 80;\n    listen [::]:80;\n    server_name panel.example.com;\n}\nserver {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n}\n";

    it('takes the [::] listens out of the installer\'s nginx files only when there is no IPv6', function (bool $ipv6, string $expected) use ($vhost) {
        $dir = reviewDir();
        file_put_contents($dir.'/panel.conf', $vhost);
        file_put_contents($dir.'/panel-tls.conf', $vhost);
        if ($ipv6) {
            touch($dir.'/if_inet6');
        }

        $run = reviewBash(installerFunctions('have_ipv6', 'nginx_without_ipv6').'
            eval "real_$(declare -f have_ipv6)"
            have_ipv6() { real_have_ipv6 '.escapeshellarg($dir.'/if_inet6').'; }
            nginx_without_ipv6 '.escapeshellarg($dir.'/panel.conf').' '.escapeshellarg($dir.'/panel-tls.conf').'
            echo "CONTINUED"');

        expect($run['out'])->toContain('CONTINUED')
            ->and(file_get_contents($dir.'/panel.conf'))->toBe($expected)
            ->and(file_get_contents($dir.'/panel-tls.conf'))->toBe($expected);
    })->with([
        'no IPv6' => [false, "server {\n    listen 80;\n    server_name panel.example.com;\n}\nserver {\n    listen 443 ssl;\n}\n"],
        'IPv6' => [true, $vhost],
    ]);

    it('runs on both files the installer writes, before nginx tests them', function () {
        foreach (['configure_nginx', 'configure_tls'] as $fn) {
            // Up to the next function: both hold heredocs with a `}` in column 0.
            preg_match('/^'.$fn.'\(\) \{\n.*?(?=^[a-z_]+\(\) \{$)/ms', installerSource(), $m);
            $code = $m[0] ?? '';

            expect($code)->toContain('nginx_without_ipv6')
                ->and(strpos($code, 'nginx_without_ipv6'))->toBeLessThan(strpos($code, 'nginx -t'));
        }
    });

    function nginxRoom(string $webServer, bool $ipv6, ?string $existing): array
    {
        $dir = reviewDir();
        mkdir($dir.'/sites-available');
        file_put_contents($dir.'/sites-available/default', "server { listen [::]:80 default_server; }\n");
        if ($ipv6) {
            touch($dir.'/if_inet6');
        }
        if ($existing === 'link') {
            mkdir($dir.'/sites-enabled');
            symlink($dir.'/sites-available/default', $dir.'/sites-enabled/default');
        } elseif ($existing !== null) {
            mkdir($dir.'/sites-enabled');
            file_put_contents($dir.'/sites-enabled/default', $existing);
        }

        $run = reviewBash(installerFunctions('have_ipv6', 'make_room_for_nginx_without_ipv6').'
            WEB_SERVER='.escapeshellarg($webServer).'
            make_room_for_nginx_without_ipv6 '.escapeshellarg($dir.'/if_inet6').' '.escapeshellarg($dir.'/sites-enabled').'
            echo "CONTINUED"');

        $default = $dir.'/sites-enabled/default';

        return $run + [
            'default' => is_link($default) ? 'link' : (is_file($default) ? (string) file_get_contents($default) : null),
            'stock' => (string) file_get_contents($dir.'/sites-available/default'),
        ];
    }

    it('keeps the package\'s [::]:80 default site out before nginx is installed', function (?string $existing) {
        $run = nginxRoom('nginx', false, $existing);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('CONTINUED')
            ->and($run['default'])->not->toBeNull()
            ->and($run['default'])->not->toBe('link')
            ->and($run['default'])->not->toMatch('/^\s*(listen|server)\b/m')
            // The package's own file is left as it ships.
            ->and($run['stock'])->toContain('[::]:80');
    })->with(['fresh server' => [null], 'the stock link from an earlier failed run' => ['link']]);

    it('leaves the default site alone with IPv6, on another web server, or when it is a real file', function (string $webServer, bool $ipv6, ?string $existing, ?string $expected) {
        $run = nginxRoom($webServer, $ipv6, $existing);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['default'])->toBe($expected);
    })->with([
        'IPv6 present' => ['nginx', true, null, null],
        'apache' => ['apache', false, null, null],
        'openlitespeed' => ['openlitespeed', false, null, null],
        'operator file' => ['nginx', false, "server { listen 80; }\n", "server { listen 80; }\n"],
    ]);

    it('does it before the one big package transaction, and configure_nginx removes it again', function () {
        $packages = installerFn('install_packages');

        expect(strpos($packages, 'make_room_for_nginx_without_ipv6'))->toBeLessThan(strpos($packages, 'apt-get install'))
            ->and(installerSource())->toMatch('/^configure_nginx\(\) \{\n(?:(?!^[a-z_]+\(\) \{$).)*rm -f \/etc\/nginx\/sites-enabled\/default/ms');
    });
});

describe('R13: a failed install gives needrestart its unit back', function () {
    /*
     * protect_from_needrestart writes a conf.d drop-in naming the unit the
     * install runs as (Central's systemd-run), and only finish() removed it.
     * On a real server whose install failed, the drop-in was still there
     * afterwards, and needrestart went on skipping that unit's name.
     */
    function needrestartExit(string $failure): array
    {
        $dir = reviewDir();
        mkdir($dir.'/confd');
        file_put_contents($dir.'/cgroup', "0::/system.slice/panel-install.service\n");

        $run = reviewBash(installerFunctions('protect_from_needrestart', 'release_needrestart', 'on_exit').'
            progress_stop() { :; }
            die() { echo "DIE: $*"; exit 1; }
            PANEL_SLUG=panel; NEEDRESTART_GUARD=""; RUN_DIR=$(mktemp -d)
            trap on_exit EXIT
            protect_from_needrestart '.escapeshellarg($dir.'/cgroup').' '.escapeshellarg($dir.'/confd').'
            ls '.escapeshellarg($dir.'/confd').'
            '.$failure);

        return $run + ['left' => array_values(array_diff(scandir($dir.'/confd'), ['.', '..']))];
    }

    it('removes the guard when the install dies or a command fails', function (string $failure) {
        $run = needrestartExit($failure);

        expect($run['code'])->not->toBe(0)
            // It was there while the install ran...
            ->and($run['out'])->toContain('zz-panel-install.conf')
            // ...and is gone once it stopped.
            ->and($run['left'])->toBe([]);
    })->with([
        'die' => ['die "npm run build failed"'],
        'a failing command under set -e' => ['false'],
    ]);

    it('still exits cleanly after a successful install has already released it', function () {
        $run = needrestartExit('release_needrestart; echo FINISHED');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('FINISHED')
            ->and($run['out'])->not->toContain('ERR-TRAP')
            ->and($run['left'])->toBe([]);
    });
});

describe('R10: a swap file the kernel will not turn off stops the install with the reason', function () {
    /*
     * configure_swap ran `swapoff "$swapfile" 2>/dev/null || true` and then
     * `rm -f`. When the kernel refuses swapoff (what the file holds does not
     * fit back into RAM), the review expected the file to be deleted while in
     * use. On a real server (kernel 6.8) the kernel refused the unlink too, so
     * nothing was lost, but the install died on "rm -f /swapfile-panel" with
     * swapoff's "Cannot allocate memory" thrown away. PANEL_SWAP_MB went into
     * arithmetic and fallocate unchecked.
     */
    function swapResize(int $ours, bool $swapoffWorks): array
    {
        $dir = reviewDir();
        file_put_contents($dir.'/swapfile-panel', 'in use');

        $run = reviewBash(installerFunctions('swap_to_add_mb', 'configure_swap').'
            step() { :; }; run() { echo "RUN: $*"; }
            die() { echo "DIE: $*"; exit 1; }
            # fd 9 is the real stdout: configure_swap sends swapoff to the log.
            exec 9>&1
            swapoff() { echo "SWAPOFF $*" >&9; '.($swapoffWorks ? 'return 0' : 'echo "swapoff: $1: swapoff failed: Cannot allocate memory" >&2; return 255').'; }
            req_ram_mb() { echo 3915; }; req_swap_mb() { echo '.$ours.'; }; req_our_swap_mb() { echo '.$ours.'; }
            PANEL_SWAPFILE='.escapeshellarg($dir.'/swapfile-panel').'; LOG_FILE=/dev/null; SWAP_UNAVAILABLE=0
            # PANEL_SWAP_MB sets the size, so the sizing constants are not read.
            BUILD_MEMORY_MB=4096; MINIMUM_SWAP_MB=1024; SWAP_TOLERANCE_MB=64
            PANEL_SWAP_MB=2048
            set +e
            (configure_swap) 2>&1 | grep -v "/etc/fstab"
            echo "EXIT ${PIPESTATUS[0]}"');

        return $run + ['kept' => is_file($dir.'/swapfile-panel')];
    }

    it('stops, and deletes nothing, when the kernel will not turn the swap file off', function () {
        $run = swapResize(1023, false);

        expect($run['out'])->toContain('DIE: could not turn off')
            ->and($run['out'])->toContain('Nothing has been deleted')
            ->and($run['out'])->not->toContain('RUN: rm -f')
            ->and($run['out'])->not->toContain('RUN: fallocate')
            ->and($run['out'])->toContain('EXIT 1')
            ->and($run['kept'])->toBeTrue();
    });

    it('turns it off, then replaces it, when the kernel lets it go', function () {
        $run = swapResize(1023, true);

        expect($run['out'])->toContain('SWAPOFF')
            ->and($run['out'])->toContain('RUN: rm -f')
            ->and($run['out'])->toContain('RUN: fallocate -l 2048M')
            ->and($run['out'])->toContain('ok: 2048 MB swap active');
    });

    it('replaces a swap file that is not on without asking swapoff', function () {
        $run = swapResize(0, false);

        expect($run['out'])->not->toContain('SWAPOFF')
            ->and($run['out'])->not->toContain('DIE')
            ->and($run['out'])->toContain('RUN: fallocate -l 2048M')
            ->and($run['out'])->toContain('ok: 2048 MB swap active');
    });

    it('accepts a whole number of MB in PANEL_SWAP_MB', function (string $mb) {
        $run = inputsRun([], ['PANEL_SWAP_MB' => $mb]);

        expect($run['code'])->toBe(0, $run['out'])->and($run['out'])->toContain('ACCEPTED');
    })->with(['0', '512', '2048', '65536']);

    it('refuses anything else before changing anything', function (string $mb) {
        $run = inputsRun([], ['PANEL_SWAP_MB' => $mb]);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: PANEL_SWAP_MB must be a whole number of MB')
            ->and($run['out'])->toContain('Nothing has been changed on this server.');
    })->with(['2G', 'abc', '-1', '1e3', '08', '2048 ', '1234567', '$(id)']);
});

describe('S6: the self-signed nginx fallback is TLS 1.2+ and plain HTTP redirects', function () {
    /*
     * When Let's Encrypt failed, the nginx fallback's 443 blocks set no
     * ssl_protocols, so they took nginx.conf's stock list (TLS 1.0 and 1.1
     * included), and port 80 went on serving the whole panel, logins and all,
     * in plain text beside the encrypted copy.
     */
    function selfSigned(bool $singleHost, bool $valid): array
    {
        $root = reviewDir();
        foreach (['/etc/nginx/sites-available', '/etc/nginx/sites-enabled', '/etc/nginx/snippets', '/etc/ssl'] as $dir) {
            @mkdir($root.$dir, 0777, true);
        }

        $run = reviewBash(reviewRooted(
            installerFunctions('have_ipv6', 'nginx_without_ipv6')."\n"
            .installerFnBefore('configure_nginx', 'configure_apache')."\n"
            .installerFnBefore('configure_tls', 'certbot_issue'),
            $root,
            ['/etc/nginx/', '/etc/ssl/'],
        ).'
            run() { "$@"; }
            step() { :; }
            have_ipv6() { return 0; }
            certbot_issue() { return 1; }
            openssl() { :; }; systemctl() { :; }
            nginx() { '.($valid ? 'return 0' : 'grep -q "return 301" '.escapeshellarg($root).'/etc/nginx/sites-available/panel.conf && return 1; return 0').'; }
            WANT_SSL=1; WEB_SERVER=nginx; PANEL_SLUG=panel; FRONTEND_PORT=3000
            PANEL_HOST=panel.example.com; API_HOST='.($singleHost ? 'panel.example.com' : 'api.example.com').'
            SINGLE_HOST='.($singleHost ? 1 : 0).'; ADMIN_EMAIL=""; DOMAIN=""; APP_DIR=/var/www/panel; LOG_FILE=/dev/null
            configure_nginx
            cp '.escapeshellarg($root).'/etc/nginx/sites-available/panel.conf '.escapeshellarg($root).'/http-before.conf
            configure_tls
            echo "TLS_STATE=$TLS_STATE"');

        $read = fn (string $path) => is_file($root.$path) ? (string) file_get_contents($root.$path) : null;

        return $run + [
            'tls' => $read('/etc/nginx/sites-available/panel-tls.conf'),
            'http' => $read('/etc/nginx/sites-available/panel.conf'),
            'before' => $read('/http-before.conf'),
            'enabled' => is_link($root.'/etc/nginx/sites-enabled/panel-tls.conf'),
            'leftover' => glob($root.'/etc/nginx/sites-available/*.pre-tls') ?: [],
        ];
    }

    it('sets TLS 1.2 as the floor on every 443 block', function (bool $singleHost) {
        $run = selfSigned($singleHost, valid: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('TLS_STATE=self-signed')
            ->and(substr_count((string) $run['tls'], 'ssl_protocols TLSv1.2 TLSv1.3;'))
            ->toBe(substr_count((string) $run['tls'], 'listen 443 ssl;'))
            ->toBe($singleHost ? 1 : 2);
    })->with(['one host' => [true], 'panel and API hosts' => [false]]);

    it('sends plain HTTP to HTTPS and keeps the ACME challenge on port 80', function (bool $singleHost) {
        $run = selfSigned($singleHost, valid: true);
        $blocks = substr_count((string) $run['http'], 'server {');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($blocks)->toBe($singleHost ? 1 : 2)
            ->and($run['http'])->not->toContain('include ')
            ->and(substr_count((string) $run['http'], 'location / { return 301 https://$host$request_uri; }'))->toBe($blocks)
            ->and(substr_count((string) $run['http'], 'location /.well-known/acme-challenge/ { root '))->toBe($blocks)
            ->and($run['leftover'])->toBe([]);
    })->with(['one host' => [true], 'panel and API hosts' => [false]]);

    it('puts the port-80 site back as it was when nginx rejects the result', function () {
        $run = selfSigned(false, valid: false);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('TLS_STATE=none')
            ->and($run['http'])->toBe($run['before'])
            ->and($run['http'])->toContain('include ')
            ->and($run['enabled'])->toBeFalse()
            ->and($run['leftover'])->toBe([]);
    });
});

describe('S7: the install state file is for administrators only', function () {
    /*
     * The login script shows the install's status to root and the sudo group
     * only, but the file it reads was 0644: any account on the box, a site's
     * own SSH user included, could read the failure reason, which can quote a
     * command line.
     */
    function stateFile(bool $chgrpWorks): array
    {
        $dir = reviewDir();

        $run = reviewBash(installerFunctions('save_install_state', 'one_line', 'branding_name', 'panel_units', 'installed_version').'
            send_progress() { :; }
            chgrp() { echo "chgrp $*" >>'.escapeshellarg($dir.'/calls').'; '.($chgrpWorks ? 'return 0' : 'return 1').'; }
            LOGIN_STATUS=1; INSTALL_STATE_DIR='.escapeshellarg($dir).'
            STEP_NO=3; STEP_TOTAL=9; CURRENT_STEP="Installing packages"; INSTALL_STARTED=1; LOG_FILE=/var/log/panel-install.log; FRESH_CHECK=passed
            save_install_state failed "RUN FAILED: some command --with=arguments"
            echo "CONTINUED"');

        $state = $dir.'/state';

        return $run + [
            'calls' => is_file($dir.'/calls') ? (string) file_get_contents($dir.'/calls') : '',
            'mode' => is_file($state) ? fileperms($state) & 0777 : null,
            'state' => is_file($state) ? (string) file_get_contents($state) : '',
            'temps' => glob($dir.'/.state.*') ?: [],
        ];
    }

    it('writes it 0640, group sudo', function () {
        $run = stateFile(chgrpWorks: true);

        expect($run['out'])->toContain('CONTINUED')
            ->and($run['calls'])->toMatch('/^chgrp sudo \S+\/\.state\.\w+$/m')
            ->and($run['mode'])->toBe(0640)
            ->and($run['state'])->toContain("STATE=failed\n")
            ->and($run['state'])->toContain("REASON=RUN FAILED: some command --with=arguments\n")
            ->and($run['temps'])->toBe([]);
    });

    it('still writes it, readable by its owner only, when there is no sudo group', function () {
        $run = stateFile(chgrpWorks: false);

        expect($run['out'])->toContain('CONTINUED')
            ->and($run['mode'])->toBe(0640)
            ->and($run['state'])->toContain("STATE=failed\n")
            ->and($run['temps'])->toBe([]);
    });
});

describe('R8: a failed copy of the interface\'s assets fails its start', function () {
    /*
     * The frontend unit's ExecStartPre ended in `2>/dev/null || true`, which
     * covered the whole `&&` chain (`sh -c 'false && true || true'` exits 0):
     * when copying .next/static failed, the interface started anyway, served
     * pages with no CSS or JS, and nothing said why.
     */
    function assetCopy(bool $static, bool $public): array
    {
        $line = collect(explode("\n", installedUnits()['frontend']))
            ->first(fn (string $l) => str_starts_with($l, 'ExecStartPre='));

        $dir = reviewDir();
        mkdir($dir.'/.next', 0777, true);
        if ($static) {
            mkdir($dir.'/.next/static/chunks', 0777, true);
            file_put_contents($dir.'/.next/static/chunks/app.js', 'js');
        }
        if ($public) {
            mkdir($dir.'/public');
            file_put_contents($dir.'/public/favicon.ico', 'ico');
        }

        exec('cd '.escapeshellarg($dir).' && '.substr((string) $line, strlen('ExecStartPre=')).' 2>&1', $out, $code);

        return [
            'line' => $line,
            'code' => $code,
            'static' => is_file($dir.'/.next/standalone/.next/static/chunks/app.js'),
            'public' => is_file($dir.'/.next/standalone/public/favicon.ico'),
        ];
    }

    it('copies the assets and public/', function () {
        expect(assetCopy(static: true, public: true))->toMatchArray(['code' => 0, 'static' => true, 'public' => true]);
    });

    it('starts without public/, which is optional', function () {
        expect(assetCopy(static: true, public: false))->toMatchArray(['code' => 0, 'static' => true, 'public' => false]);
    });

    it('fails when the assets cannot be copied', function () {
        expect(assetCopy(static: false, public: true)['code'])->not->toBe(0);
    });

    it('writes the line the panel puts on existing servers', function () {
        expect(assetCopy(static: true, public: true)['line'])->toBe(FrontendUnit::ASSET_COPY);
    });

    it('replaces the old line on existing servers through panel:frontend-unit, and only that exact line', function () {
        $unit = "[Service]\nUser=panel\n".FrontendUnit::LEGACY_ASSET_COPY."\nEnvironment=PORT=3100\n".FrontendUnit::LISTEN."\n".FrontendUnit::NO_NEW_PRIVILEGES."\n";

        expect(FrontendUnit::desired($unit))->toBe(str_replace(FrontendUnit::LEGACY_ASSET_COPY, FrontendUnit::ASSET_COPY, $unit))
            ->and(FrontendUnit::desired(str_replace(FrontendUnit::LEGACY_ASSET_COPY, FrontendUnit::ASSET_COPY, $unit)))->toBeNull()
            // An operator's own line is theirs.
            ->and(FrontendUnit::withStrictAssetCopy("[Service]\nExecStartPre=/bin/sh -c 'cp -a .next/static x || true'\n"))->toBeNull();

        $dir = reviewDir();
        config(['server.applications.systemd_dir' => $dir, 'panel_update.services.frontend' => 'panel-frontend.service']);
        file_put_contents("{$dir}/panel-frontend.service", $unit);
        Process::fake();

        $this->artisan('panel:frontend-unit')->assertSuccessful();

        expect(file_get_contents("{$dir}/panel-frontend.service"))->toContain(FrontendUnit::ASSET_COPY)
            ->not->toContain('|| true');
        Process::assertRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
    });
});
