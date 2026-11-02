<?php

use App\Services\Panel\FrontendUnit;

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
            ->and($run['out'])->toContain('DIE: '.$message);
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
