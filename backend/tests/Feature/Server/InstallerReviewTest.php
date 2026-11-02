<?php

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
