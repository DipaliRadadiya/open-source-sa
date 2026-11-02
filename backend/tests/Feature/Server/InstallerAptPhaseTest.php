<?php

/*
 * Every apt operation of the install happens in one block, the package step,
 * and none after it.
 *
 * Measured on seven fresh 4 GB servers (2026-11-01): Ubuntu's first-boot
 * unattended-upgrades queues behind our package work, takes the lock in the
 * first gap of a few seconds, and holds it for 6–9 minutes. The next apt call
 * of ours (Postfix, or certbot) waited all of that. With every package in
 * first, Ubuntu's updates run beside the configuration and the build instead.
 *
 * These hold that shape: the guard, the order, the one list per stack, one
 * refresh then one install, and the work done while the lock is held anyway.
 */

/** @return array{code: int, out: string} */
function aptPhaseRun(string $body, string ...$functions): array
{
    $script = 'set -Eeuo pipefail
        '.installerFunctions(...$functions).'
        say() { printf "%s\n" "$*"; }
        ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; warn() { echo "warn: $*"; }
        step() { echo "step: $*"; }
        BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
        DRY_RUN=0; RUN_DIR=""; PROGRESS_ACTIVITY=0; PROGRESS_READY=0
        '.$body;

    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** Every function install.sh defines. */
function installerFunctionNames(): array
{
    preg_match_all('/^([a-z_][a-z0-9_]*)\(\) \{/m', installerSource(), $m);

    return $m[1];
}

/**
 * $name and every install.sh function it calls, all the way down — except the
 * ones named in $stop, which are followed no further.
 *
 * @return array<string, string> name => source
 */
function installerCallClosure(string $name, array $stop = []): array
{
    $known = installerFunctionNames();
    $seen = [];
    $queue = [$name];

    while ($queue !== []) {
        $fn = array_shift($queue);
        if (isset($seen[$fn]) || in_array($fn, $stop, true)) {
            continue;
        }
        // One-liners (say, ok, die…) have nothing worth following.
        if (! preg_match('/^'.preg_quote($fn, '/').'\(\) \{\n/m', installerSource())) {
            continue;
        }
        $seen[$fn] = installerFn($fn);

        $code = implode("\n", array_filter(explode("\n", $seen[$fn]), fn ($l) => ! str_starts_with(ltrim($l), '#')));
        foreach ($known as $candidate) {
            if ($candidate !== $fn && preg_match('/(?<![\w-])'.preg_quote($candidate, '/').'(?![\w-])/', $code)) {
                $queue[] = $candidate;
            }
        }
    }

    return $seen;
}

/** The functions main() calls, in order. */
function mainCalls(): array
{
    preg_match_all('/^    ([a-z_][a-z0-9_]*)\s*$/m', installerFn('main'), $m);

    return $m[1];
}

const APT_WRITES = '/apt-get[^\n]*\s(install|update|upgrade|dist-upgrade|remove|purge)\b|add-apt-repository|dpkg\s+-i\b/';

it('uses no apt after the package step', function () {
    $calls = mainCalls();
    $after = array_slice($calls, array_search('install_packages', $calls, true) + 1);

    expect($after)->toContain('configure_mail')->toContain('configure_tls')->toContain('build_frontend');

    foreach ($after as $call) {
        // install_ols_packages is reached from configure_ols and is a no-op
        // there: install_packages already ran it (OLS_PACKAGES_DONE).
        foreach (installerCallClosure($call, ['install_ols_packages', 'install_packages']) as $fn => $source) {
            $code = implode("\n", array_filter(explode("\n", $source), fn ($l) => ! str_starts_with(ltrim($l), '#')));

            expect(preg_match(APT_WRITES, $code))->toBe(0, "{$fn}() (reached from {$call}) runs apt after the package step");
        }
    }

    expect(installerFn('install_ols_packages'))->toContain('if (( OLS_PACKAGES_DONE )); then')
        ->and(installerFn('install_packages'))->toContain('install_ols_packages');
});

it('does only package-free work while it waits for the lock', function () {
    foreach (['install_node_body', 'fetch_source_body', 'frontend_deps'] as $item) {
        foreach (installerCallClosure($item) as $fn => $source) {
            $code = implode("\n", array_filter(explode("\n", $source), fn ($l) => ! str_starts_with(ltrim($l), '#')));

            expect(preg_match(APT_WRITES, $code))->toBe(0, "{$fn}() (early item {$item}) uses apt");
        }
    }
});

it('runs the steps in the order the overlap depends on', function () {
    $calls = mainCalls();
    $at = fn (string $fn): int => (int) array_search($fn, $calls, true);

    foreach (['create_user', 'install_packages', 'install_node', 'fetch_source', 'configure_mail', 'configure_tls', 'setup_backend', 'build_frontend'] as $fn) {
        expect($calls)->toContain($fn);
    }

    expect($at('protect_from_needrestart'))->toBeLessThan($at('install_packages'))
        ->and($at('create_user'))->toBeLessThan($at('install_packages'))
        ->and($at('install_packages'))->toBeLessThan($at('install_node'))
        ->and($at('install_node'))->toBeLessThan($at('fetch_source'))
        ->and($at('fetch_source'))->toBeLessThan($at('configure_mail'))
        ->and($at('configure_mail'))->toBeLessThan($at('configure_tls'))
        // The build stays after the API: it prerenders /api/branding.
        ->and($at('configure_tls'))->toBeLessThan($at('setup_backend'))
        ->and($at('setup_backend'))->toBeLessThan($at('build_frontend'));
});

describe('the one package list', function () {
    /** panel_packages() for a stack, as a list. */
    function packagesFor(string $stack, string $web, string $sapi, int $ssl = 1): array
    {
        $run = aptPhaseRun('STACK='.$stack.'; WEB_SERVER='.$web.'; PANEL_PHP_SAPI='.$sapi.'; WANT_SSL='.$ssl.'; PHP_VERSION=8.4
            panel_packages', 'panel_packages', 'panel_php_packages');
        expect($run['code'])->toBe(0, $run['out']);

        return explode("\n", $run['out']);
    }

    $prerequisites = ['software-properties-common', 'curl', 'git', 'unzip', 'zip', 'rsync', 'ca-certificates', 'gnupg', 'update-notifier-common', 'build-essential', 'pigz', 'acl'];
    $base = ['redis-server', 'sqlite3', 'supervisor'];
    $docker = ['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin'];

    it('has everything lemp needs in it', function () use ($prerequisites, $base, $docker) {
        $list = packagesFor('lemp', 'nginx', 'fpm');

        expect($list)->toContain(...$prerequisites, ...$base)
            ->toContain('nginx', 'php8.4-fpm', 'php8.4-cli', 'php8.4-sqlite3', 'php8.4-gmp', 'certbot', 'python3-certbot-nginx')
            ->not->toContain('apache2')
            ->not->toContain('postfix')
            ->not->toContain('docker-ce');
        expect(array_values(array_diff($list, $docker)))->toBe($list);
    });

    it('swaps the web server and certbot plugin for lamp', function () {
        expect(packagesFor('lamp', 'apache', 'fpm'))
            ->toContain('apache2', 'php8.4-fpm', 'certbot', 'python3-certbot-apache')
            ->not->toContain('nginx')
            ->not->toContain('python3-certbot-nginx');
    });

    it('has OpenLiteSpeed and certbot but no ondrej PHP on ols', function () use ($base) {
        $list = packagesFor('ols', 'openlitespeed', 'lsapi');

        expect($list)->toContain('openlitespeed', 'certbot', ...$base)
            ->not->toContain('python3-certbot-nginx')
            ->not->toContain('postfix');
        expect(array_filter($list, fn ($p) => str_starts_with($p, 'php8.4-')))->toBe([]);
    });

    it('adds the five Docker packages on docker', function () use ($docker) {
        expect(packagesFor('docker', 'nginx', 'fpm'))->toContain('nginx', 'php8.4-fpm', ...$docker);
    });

    it('has no certbot with --no-ssl', function () {
        expect(packagesFor('lemp', 'nginx', 'fpm', 0))
            ->not->toContain('certbot')
            ->not->toContain('python3-certbot-nginx');
    });
});

describe('the package step', function () {
    /** install_packages() with apt and the sources recorded, in order. */
    function packageStep(string $stack, string $web, string $sapi, string $extra = ''): array
    {
        return aptPhaseRun('
            STACK='.$stack.'; WEB_SERVER='.$web.'; PANEL_PHP_SAPI='.$sapi.'; WANT_SSL=1; PHP_VERSION=8.4
            OS_VERSION_ID=24.04; PANEL_PHP_BIN=/usr/bin/php8.4; LOG_FILE=/dev/null; EARLY_WORK=0
            POSTFIX_STATE=""; OLS_PACKAGES_DONE=0
            RUN_DIR=$(mktemp -d); trap "rm -rf $RUN_DIR" EXIT
            # fd 9 is the real stdout: the output of apt goes to the log.
            exec 9>&1; rec() { echo "REC $*" >&9; }
            run() { "$@"; }
            progress_start() { :; }; progress_stop() { :; }
            # Recorded without the status-fd options the ticker adds.
            apt-get() { local a=("$@"); [[ "${a[-2]:-}" == "-o" ]] && a=("${a[@]:0:${#a[@]}-2}"); rec "apt-get ${a[*]}"; [[ " ${a[*]} " == *" postfix "* ]] && : >"$RUN_DIR/postfix"; return 0; }
            debconf-set-selections() { cat >/dev/null; }
            dpkg-query() { [[ -e "$RUN_DIR/postfix" ]] && echo "install ok installed"; }
            configure_apt_lock_wait() { rec "lock-config"; }
            wait_for_apt_lock() { rec "wait"; }
            repair_panel_mongodb_repository() { rec "mongodb-repair"; }
            install_source_tools() { rec "source-tools"; }
            add_package_sources() { rec "sources"; }
            ensure_php_available() { rec "php-candidate"; }
            systemctl() { rec "systemctl $*"; }; docker() { echo "Docker 1"; }; ln() { rec "ln $*"; }
            composer() { :; }
            '.$extra.'
            install_packages', 'install_packages', 'run_apt', 'try_apt', 'panel_packages', 'panel_php_packages', 'install_postfix', 'preseed_postfix', 'install_ols_packages');
    }

    it('writes every source, refreshes once, then installs everything at once', function () {
        $run = packageStep('lemp', 'nginx', 'fpm');
        preg_match_all('/^REC (.*)$/m', $run['out'], $m);

        expect($run['code'])->toBe(0, $run['out']);

        $recs = $m[1];
        $installs = array_values(array_filter($recs, fn ($r) => str_starts_with($r, 'apt-get install')));
        $updates = array_values(array_filter($recs, fn ($r) => str_starts_with($r, 'apt-get update')));

        // ONE install, Postfix in it: a gap before a call of its own is where
        // Ubuntu's updates took the lock on a real server (2026-11-02).
        expect($updates)->toBe(['apt-get update -qq'])
            ->and($installs)->toHaveCount(1)
            ->and($installs[0])->toStartWith('apt-get install -y software-properties-common')
            ->and($installs[0])->toContain(' nginx ')->toContain(' php8.4-fpm ')->toContain(' python3-certbot-nginx')
            ->and($installs[0])->toEndWith(' postfix');

        // Order: sources, then the one refresh, then PHP is asked of apt, then
        // the one install.
        $at = fn (string $prefix) => (int) array_key_first(array_filter($recs, fn ($r) => str_starts_with($r, $prefix)));
        expect($at('source-tools'))->toBeLessThan($at('sources'))
            ->and($at('mongodb-repair'))->toBeLessThan($at('apt-get update'))
            ->and($at('sources'))->toBeLessThan($at('apt-get update'))
            ->and($at('apt-get update'))->toBeLessThan($at('php-candidate'))
            ->and($at('php-candidate'))->toBeLessThan($at('apt-get install -y software-properties-common'));
    });

    it('puts lsphp in the same one install on OpenLiteSpeed, and never asks for ondrej PHP', function () {
        $run = packageStep('ols', 'openlitespeed', 'lsapi');
        preg_match_all('/^REC (.*)$/m', $run['out'], $m);
        $installs = array_values(array_filter($m[1], fn ($r) => str_starts_with($r, 'apt-get install')));

        expect($run['code'])->toBe(0, $run['out'])
            ->and($m[1])->not->toContain('php-candidate')
            ->and($installs)->toHaveCount(1)
            ->and($installs[0])->toContain(' openlitespeed ')->toContain(' postfix ')
            ->toContain(' lsphp84 ')->toContain(' lsphp84-opcache ')->toContain(' lsphp84-imagick ');
    });

    it('falls back to the required list alone, then the optional ones non-fatally, when one fails', function () {
        // The whole list fails once (an optional name apt cannot install);
        // the required ones alone work.
        $run = packageStep('ols', 'openlitespeed', 'lsapi', '
            apt-get() { local a=("$@"); [[ "${a[-2]:-}" == "-o" ]] && a=("${a[@]:0:${#a[@]}-2}"); rec "apt-get ${a[*]}"
                [[ " ${a[*]} " == *" postfix "* && " ${a[*]} " == *" openlitespeed "* ]] && return 100
                [[ " ${a[*]} " == *" lsphp84-imagick "* ]] && return 100
                [[ " ${a[*]} " == *" postfix "* ]] && : >"$RUN_DIR/postfix"; return 0; }
            trap \'echo "POSTFIX_STATE=$POSTFIX_STATE"; rm -rf "$RUN_DIR"\' EXIT');
        preg_match_all('/^REC (apt-get install.*)$/m', $run['out'], $m);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('warn: one of the optional packages')
            // R7: the panel's own lsphp core is required (fatal), the sites'
            // database drivers are not, so they are two separate calls.
            ->and($m[1])->toHaveCount(6)
            ->and($m[1][1])->toContain(' openlitespeed')->not->toContain('postfix')->not->toContain('lsphp')
            ->and($m[1][2])->toBe('apt-get install -y postfix')
            ->and($m[1][3])->toStartWith('apt-get install -y lsphp84 lsphp84-common')->not->toContain('-mysql')
            ->and($m[1][4])->toBe('apt-get install -y lsphp84-mysql lsphp84-pgsql')
            ->and($m[1][5])->toBe('apt-get install -y lsphp84-imagick lsphp84-imap')
            ->and($run['out'])->toContain('warn: lsphp84 has no imagick/imap package')
            ->and($run['out'])->toContain('POSTFIX_STATE=installed');
    });

    it('starts Docker after the one install on docker', function () {
        $run = packageStep('docker', 'nginx', 'fpm');

        expect($run['out'])->toContain('REC systemctl enable --now docker')
            ->and($run['out'])->toContain('docker-compose-plugin');
    });

    it('leaves a Postfix that was already there alone', function () {
        $run = packageStep('lemp', 'nginx', 'fpm', 'dpkg-query() { echo "install ok installed"; }
            trap \'echo "POSTFIX_STATE=$POSTFIX_STATE"; rm -rf "$RUN_DIR"\' EXIT');

        expect($run['out'])->not->toContain(' postfix')
            ->and($run['out'])->toContain('POSTFIX_STATE=preinstalled');
    });

    it('adds the ondrej PPA with no refresh of its own, and refreshes nothing itself', function () {
        $fn = installerFn('add_php_repository');

        expect($fn)->toContain('add-apt-repository -y -n ppa:ondrej/php')
            ->and(preg_match('/apt-get[^\n]*update/', $fn))->toBe(0)
            ->and(preg_match('/apt-get[^\n]*update/', installerFn('add_docker_repository')))->toBe(0)
            ->and(preg_match('/apt-get[^\n]*update/', installerFn('add_ols_repository')))->toBe(0);
    });

    it('fetches the tools for the sources only when one is missing', function () {
        $present = aptPhaseRun('PANEL_PHP_SAPI=fpm; OS_VERSION_ID=24.04
            curl() { :; }; gpg() { :; }; add-apt-repository() { :; }
            wait_for_apt_lock() { :; }; run_apt() { echo "APT $*"; }
            install_source_tools; echo done', 'install_source_tools');
        $missing = aptPhaseRun('PANEL_PHP_SAPI=fpm; OS_VERSION_ID=24.04; PATH=/nonexistent
            curl() { :; }
            wait_for_apt_lock() { :; }; run_apt() { echo "APT $*"; }
            install_source_tools; echo done', 'install_source_tools');

        expect($present['out'])->toBe('done')
            ->and($missing['out'])->toContain('APT Refreshing system package lists apt-get update -qq')
            ->and($missing['out'])->toContain('APT Installing gnupg software-properties-common apt-get install -y gnupg software-properties-common');
    });
});

describe('configure_mail', function () {
    function mailStep(string $state): array
    {
        return aptPhaseRun('POSTFIX_STATE='.escapeshellarg($state).'; LOG_FILE=/dev/null; exec 9>&1
            apt-get() { echo "APT $*" >&9; }
            postconf() { echo "POSTCONF $*" >&9; }; systemctl() { echo "SYSTEMCTL $*" >&9; }
            configure_mail', 'configure_mail');
    }

    it('only configures the Postfix the package step installed, with no apt', function () {
        $run = mailStep('installed');

        expect($run['out'])->toContain('POSTCONF -e inet_interfaces = loopback-only')
            ->and($run['out'])->toContain('SYSTEMCTL restart postfix')
            ->and($run['out'])->toContain('ok: Postfix sends mail for this server')
            ->and($run['out'])->not->toContain('APT');
    });

    it('leaves a Postfix that was here before alone, and says when it could not be installed', function () {
        expect(mailStep('preinstalled')['out'])->toContain('skip: Postfix already installed')->not->toContain('POSTCONF')
            ->and(mailStep('failed')['out'])->toContain('warn: could not install Postfix')->not->toContain('POSTCONF');
    });
});

describe('work done while the lock is held', function () {
    /**
     * wait_for_apt_lock with EARLY_WORK on: the lock held for $held checks.
     * The early items are stubs that record they ran.
     */
    function earlyWait(int $held, string $items = '', string $tools = 'curl() { :; }; unzip() { :; }; git() { :; }'): array
    {
        $log = tempnam(sys_get_temp_dir(), 'early');

        $run = aptPhaseRun('
            HELD='.$held.'; APT_LOCK_WAIT=900; EARLY_WORK=1; NODE_VERSION=24
            EARLY_RAN=0; EARLY_TRIED="|"; EARLY_DONE="|"
            LOG_FILE='.escapeshellarg($log).'
            trap \'while IFS= read -r l; do echo "LOG:$l"; done <"$LOG_FILE"\' EXIT
            # Only the stubbed tools exist: command -v finds functions.
            PATH=/nonexistent
            apt_lock_is_held() { echo "CHECK"; (( HELD-- > 0 )); }
            apt_lock_holder() { printf "unattended-upgr, PID 1"; }
            ubuntu_updates_running() { return 0; }
            sleep() { SECONDS=$((SECONDS + $1)); }
            progress_stop() { :; }
            send_progress() { echo "SENT $*"; }; save_install_state() { send_progress "$@"; }
            report_failure() { echo "REPORTED $1"; save_install_state failed "$1"; }
            run() { if ! "$@"; then report_failure "This command failed."; exit 1; fi; }
            install_node_body() { echo "ITEM node"; }
            fetch_source_body() { echo "ITEM fetch"; }
            frontend_deps() { echo "ITEM deps"; }
            '.$tools.'
            '.$items.'
            wait_for_apt_lock
            echo "AFTER EARLY_DONE=$EARLY_DONE"', 'fmt_elapsed', 'apt_wait_text', 'wait_for_apt_lock', 'early_sandbox', 'early_item', 'early_work_next');
        unlink($log);

        return $run;
    }

    it('installs Node, fetches the panel and runs npm ci while waiting, checking the lock between', function () {
        $run = earlyWait(5);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('→ while we wait: installing Node 24 (fnm)...')
            ->and($run['out'])->toContain('→ while we wait: fetching the panel...')
            ->and($run['out'])->toContain("→ while we wait: downloading the interface's packages...")
            ->and($run['out'])->toContain('AFTER EARLY_DONE=|node|fetch|deps|');

        // The items' own output goes to the log, in order, the lock checked between each.
        $lines = explode("\n", $run['out']);
        $index = fn (string $needle) => (int) array_key_first(array_filter($lines, fn ($l) => str_contains($l, $needle)));
        expect($index('LOG:ITEM node'))->toBeLessThan($index('LOG:ITEM fetch'))
            ->and($index('LOG:ITEM fetch'))->toBeLessThan($index('LOG:ITEM deps'));
        expect(substr_count($run['out'], 'CHECK'))->toBe(6);
    });

    it('does nothing early when the lock is free', function () {
        $run = earlyWait(0);

        expect($run['out'])->not->toContain('while we wait')
            ->and($run['out'])->toContain('AFTER EARLY_DONE=|');
    });

    it('logs a failed item, reports nothing to Central and carries on', function () {
        $run = earlyWait(5, 'install_node_body() { echo "ITEM node"; run false; echo "NOT REACHED"; }');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('not finished while we waited — it runs again in its own step')
            ->and($run['out'])->not->toContain('SENT')
            ->and($run['out'])->not->toContain('NOT REACHED')
            ->and($run['out'])->toContain('LOG:while waiting: stopped: This command failed.')
            // npm ci needs Node, so it is not tried without it.
            ->and($run['out'])->not->toContain('LOG:ITEM deps')
            ->and($run['out'])->toContain('AFTER EARLY_DONE=|fetch|');
    });

    it('stops an item at its first failing command, as the real step would', function () {
        $run = earlyWait(5, 'fetch_source_body() { echo "ITEM fetch"; false; echo "NOT REACHED"; }');

        expect($run['out'])->not->toContain('NOT REACHED')
            ->and($run['out'])->toContain('AFTER EARLY_DONE=|node|');
    });

    it('does not try fnm without unzip', function () {
        $run = earlyWait(5, '', 'curl() { :; }; git() { :; }');

        expect($run['out'])->not->toContain('installing Node')
            ->and($run['out'])->toContain('fetching the panel');
    });

    it('skips the fetch step when the fetch already ran while waiting', function () {
        $run = aptPhaseRun('EARLY_DONE="|node|fetch|"
            fetch_source_body() { echo "FETCHED AGAIN"; }
            fetch_source', 'fetch_source');

        expect($run['out'])->toContain('skip: fetched while waiting for the package lock')
            ->and($run['out'])->not->toContain('FETCHED AGAIN');
    });
});

describe('npm ci', function () {
    /** frontend_deps() with package-lock.json and an optional marker. */
    function frontendDeps(?string $marker): array
    {
        $dir = sys_get_temp_dir().'/installer-deps-'.bin2hex(random_bytes(4));
        mkdir($dir.'/app/frontend/node_modules', 0777, true);
        mkdir($dir.'/node');
        file_put_contents($dir.'/app/frontend/package-lock.json', '{"lockfileVersion": 3}');
        file_put_contents($dir.'/node/node', "#!/bin/sh\n");
        chmod($dir.'/node/node', 0755);
        if ($marker !== null) {
            file_put_contents($dir.'/app/frontend/node_modules/.panel-ci.sha256', $marker."\n");
        }

        $run = aptPhaseRun('APP_DIR='.escapeshellarg($dir.'/app').'; APP_USER=panel; NODE_BIN='.escapeshellarg($dir.'/node/node').'
            LOG_FILE=/dev/null
            progress_start() { :; }; progress_stop() { :; }
            run() { echo "RUN $*"; }; chown() { :; }
            frontend_deps
            echo "MARKER=$(cat '.escapeshellarg($dir.'/app/frontend/node_modules/.panel-ci.sha256').' 2>/dev/null)"', 'frontend_deps', 'find_node_bin');
        exec('rm -rf '.escapeshellarg($dir));

        return $run;
    }

    it('skips npm ci when node_modules matches package-lock.json', function () {
        $run = frontendDeps(hash('sha256', '{"lockfileVersion": 3}'));

        expect($run['out'])->toContain("skip: the interface's packages (package-lock.json unchanged)")
            ->and($run['out'])->not->toContain('RUN');
    });

    it('runs npm ci and records the lock file when it does not', function () {
        $run = frontendDeps('stale');

        expect($run['out'])->toContain('RUN sudo -u panel -H env')
            ->and($run['out'])->toContain('npm --prefix')
            ->and($run['out'])->toContain('ci --no-audit --no-fund')
            ->and($run['out'])->toContain('MARKER='.hash('sha256', '{"lockfileVersion": 3}'));
    });
});
