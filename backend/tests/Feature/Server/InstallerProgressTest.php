<?php

/*
 * install.sh's progress lines, run for real under bash.
 *
 * A long apt run, a wait for Ubuntu's first-boot updates or the frontend build
 * used to print one line and then nothing for up to ten minutes. A ticker now
 * watches the command and prints a full line when there is something to say:
 * which group of packages apt is setting up (apt's own status lines), the
 * wait for Ubuntu's updates every 15 s, and otherwise "still …" after 15 s of
 * silence. Central gets the same lines only when it asks (PROGRESS_ACTIVITY=1).
 *
 * The clock is a function (now_s), so these drive the ticker tick by tick
 * without waiting for it.
 */

/**
 * Runs $body after the named install.sh functions, with output stubs.
 *
 * @return array{code: int, out: string}
 */
function progressRun(string $body, string ...$functions): array
{
    $script = 'set -Eeuo pipefail
        export LC_ALL=C
        '.installerFunctions(...$functions).'
        say() { printf "%s\n" "$*"; }
        ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; warn() { echo "warn: $*"; }
        BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
        DRY_RUN=0; RUN_DIR=""; PROGRESS_ACTIVITY=0; PROGRESS_READY=0
        '.$body;

    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** The ticker's functions, plus a clock the script moves with `tick SECONDS`. */
function tickerScript(string $body, string $statusFile = '$DIR/status'): string
{
    return 'DIR=$(mktemp -d); trap "rm -rf $DIR" EXIT
        LOG_FILE=$DIR/log; : >"$LOG_FILE"; : >"$DIR/status"
        T=1000; now_s() { echo "$T"; }
        UBUNTU=0; ubuntu_updates_running() { (( UBUNTU )); }
        progress_init "installing packages" apt_install '.$statusFile.'
        tick() { T=$((T + $1)); progress_tick; }
        apt_says() { printf "%s\n" "$1" >>"$DIR/status"; }
        log_says() { printf "%s\n" "$1" >>"$LOG_FILE"; }
        '.$body;
}

/** install.sh's one-line die(), which installerFn cannot cut out. */
function installerDie(): string
{
    preg_match('/^die\(\) \{.*\}$/m', installerSource(), $m);

    expect($m)->not->toBeEmpty('install.sh has no die()');

    return $m[0];
}

const TICKER_FUNCTIONS = ['fmt_elapsed', 'apt_group', 'progress_new_lines', 'progress_init', 'progress_say', 'progress_activity', 'progress_tick', 'apt_wait_text'];

it('formats elapsed time the way the lines say it', function (int $seconds, string $expected) {
    $run = progressRun('fmt_elapsed '.$seconds, 'fmt_elapsed');

    expect($run['out'])->toBe($expected);
})->with([
    [5, '5 s'],
    [59, '59 s'],
    [75, '1 min 15 s'],
    [135, '2 min 15 s'],
    [600, '10 min 00 s'],
]);

it('names the group a package belongs to', function (string $package, string $group) {
    $run = progressRun('apt_group '.escapeshellarg($package), 'apt_group');

    expect($run['out'])->toBe($group);
})->with([
    ['php8.4-fpm', 'PHP 8.4'],
    ['php8.4-imagick', 'PHP 8.4'],
    ['lsphp84', 'PHP 8.4 (LiteSpeed)'],
    ['lsphp84-curl', 'PHP 8.4 (LiteSpeed)'],
    ['nginx', 'nginx'],
    ['apache2', 'Apache'],
    ['openlitespeed', 'OpenLiteSpeed'],
    ['redis-server', 'Redis'],
    ['gcc-13', 'build tools'],
    ['build-essential', 'build tools'],
    ['postfix', 'local mail (Postfix)'],
    ['python3-certbot-nginx', 'certificate tool'],
    ['docker-ce', 'Docker'],
    ['containerd.io', 'Docker'],
    ['php8.4-fpm:amd64', 'PHP 8.4'],
    ['libfoo1', ''],
    ['php-common', ''],
]);

describe('the ticker', function () {
    it('names each group apt sets up once, one line per 5 s at most, and never uses \r', function () {
        $run = progressRun(tickerScript('
            apt_says "pmstatus:gcc-13:20:Unpacking gcc-13 (amd64)"; tick 2
            apt_says "pmstatus:gcc-13:40:Configuring gcc-13 (amd64)"
            apt_says "pmstatus:php8.4-fpm:42.1:Configuring php8.4-fpm (amd64)"
            apt_says "pmstatus:php8.4-cli:43:Configuring php8.4-cli (amd64)"
            apt_says "pmstatus:nginx:50:Installed nginx (amd64)"
            apt_says "pmstatus:libfoo1:51:Configuring libfoo1 (amd64)"
            tick 2; tick 2; tick 2; tick 2; tick 2; tick 2; tick 2'), ...TICKER_FUNCTIONS);

        expect($run['code'])->toBe(0, $run['out'])
            ->and(substr_count($run['out'], 'setting up build tools'))->toBe(1)
            ->and(substr_count($run['out'], 'setting up PHP 8.4'))->toBe(1)
            ->and(substr_count($run['out'], 'setting up nginx'))->toBe(1)
            ->and($run['out'])->not->toContain('libfoo1')
            ->and($run['out'])->not->toContain("\r");

        // At most one group line per 5 s: build tools at 4 s, PHP at 10 s, nginx at 16 s.
        expect($run['out'])->toContain('setting up build tools · 4 s')
            ->and($run['out'])->toContain('setting up PHP 8.4 · 10 s')
            ->and($run['out'])->toContain('setting up nginx · 16 s');
    });

    it('waits for a half-written status line rather than reading half of it', function () {
        $run = progressRun(tickerScript('
            printf "pmstatus:nginx:50:Config" >>"$DIR/status"; tick 2
            printf "uring nginx (amd64)\n" >>"$DIR/status"; tick 6'), ...TICKER_FUNCTIONS);

        expect($run['out'])->toBe('         setting up nginx · 8 s');
    });

    it('says how far apt got after 15 s of silence, and "still …" when it knows nothing', function () {
        $withApt = progressRun(tickerScript('
            apt_says "dlstatus:1:41.7:Retrieving file 12 of 40"; tick 2; tick 10; tick 4'), ...TICKER_FUNCTIONS);
        $without = progressRun(tickerScript('tick 14; tick 2; tick 14; tick 2', '""'), ...TICKER_FUNCTIONS);

        expect($withApt['out'])->toBe('         downloading 41% · 16 s')
            ->and($without['out'])->toBe("         still installing packages · 16 s\n         still installing packages · 32 s");
    });

    it('says Ubuntu is installing its updates while apt waits for its lock, every 15 s', function () {
        $run = progressRun(tickerScript('
            for i in $(seq 1 20); do
                log_says "Waiting for cache lock: Could not get lock /var/lib/dpkg/lock-frontend. It is held by process 2207 (unattended-upgr)... $((i + 11))s"
                tick 2
            done
            tick 6; tick 2'), ...TICKER_FUNCTIONS);

        $lines = explode("\n", $run['out']);

        expect($lines)->toBe([
            // From apt's own count (12 s at the first line), not from zero.
            '         Ubuntu is installing its own security updates (normal on a new server). Waiting… 12 s so far',
            '         Ubuntu is installing its own security updates (normal on a new server). Waiting… 28 s so far',
            '         Ubuntu is installing its own security updates (normal on a new server). Waiting… 44 s so far',
            "         Ubuntu's updates let go of the package lock — carrying on",
        ]);
    });

    it('names another package manager in plain words', function () {
        $run = progressRun(tickerScript('
            log_says "Waiting for cache lock: Could not get lock /var/lib/dpkg/lock. It is held by process 99 (dpkg)... 3s"; tick 2'), ...TICKER_FUNCTIONS);

        expect($run['out'])->toBe('         Another package manager is running (dpkg, PID 99). Waiting… 3 s so far');
    });

    it('treats a silent apt as waiting while Ubuntu\'s updates run', function () {
        $run = progressRun(tickerScript('UBUNTU=1; tick 2; tick 14; tick 2; tick 14'), ...TICKER_FUNCTIONS);

        expect($run['out'])->toStartWith('         Ubuntu is installing its own security updates')
            ->and(substr_count($run['out'], 'Waiting…'))->toBe(2);
    });

    it('never says the build is waiting for Ubuntu, which it builds beside on purpose', function () {
        $run = progressRun(tickerScript('UBUNTU=1; tick 16; tick 16', '""'), ...TICKER_FUNCTIONS);

        expect($run['out'])->not->toContain('Ubuntu')
            ->and($run['out'])->toContain('still installing packages · 16 s');
    });
});

describe('the ticker process', function () {
    it('is gone after progress_stop, sleep child included', function () {
        $run = progressRun('LOG_FILE=$(mktemp); now_s() { date +%s; }; ubuntu_updates_running() { return 1; }
            progress_start "testing" heartbeat
            pid=$PROGRESS_PID; sleep 0.3
            kill -0 "$pid" && echo "RUNNING"
            progress_stop
            sleep 0.2
            kill -0 "$pid" 2>/dev/null && echo "STILL-THERE" || echo "GONE"
            # Its sleep too: an orphaned one stays in this process group.
            pgrep -x sleep -g "$(ps -o pgid= $$ | tr -d " ")" >/dev/null && echo "CHILD-LEFT" || echo "NO-CHILD"
            rm -f "$LOG_FILE"', ...[...TICKER_FUNCTIONS, 'progress_start', 'progress_stop']);

        expect($run['out'])->toBe("RUNNING\nGONE\nNO-CHILD");
    });

    it('does not outlive an install that dies while it runs', function () {
        $pidFile = tempnam(sys_get_temp_dir(), 'ticker');

        $run = progressRun('LOG_FILE=$(mktemp); now_s() { date +%s; }; ubuntu_updates_running() { return 1; }
            report_failure() { echo "FAILED: $1"; }
            '.installerDie().'
            trap on_exit EXIT
            progress_start "testing" heartbeat
            echo "$PROGRESS_PID" >'.escapeshellarg($pidFile).'
            die "it broke"', ...[...TICKER_FUNCTIONS, 'progress_start', 'progress_stop', 'on_exit']);

        usleep(300000);
        $pid = (int) trim((string) file_get_contents($pidFile));
        unlink($pidFile);

        expect($run['code'])->toBe(1)
            ->and($run['out'])->toContain('FAILED: it broke')
            ->and($pid)->toBeGreaterThan(0)
            ->and(file_exists("/proc/{$pid}"))->toBeFalse();
    });
});

describe('wait_for_apt_lock', function () {
    /** The lock held for $held checks; sleep moves SECONDS on instead of sleeping. */
    function lockWait(int $held, string $holder, string $extra = ''): array
    {
        return progressRun('
            HELD='.$held.'; APT_LOCK_WAIT=900; EARLY_WORK=0
            apt_lock_is_held() { (( HELD-- > 0 )); }
            apt_lock_holder() { printf "%s" '.escapeshellarg($holder).'; }
            ubuntu_updates_running() { [[ '.escapeshellarg($holder).' == *unattended* ]]; }
            sleep() { SECONDS=$((SECONDS + $1)); }
            die() { echo "DIE: $*"; exit 1; }
            '.$extra.'
            wait_for_apt_lock; echo "RC=$?"', 'fmt_elapsed', 'apt_wait_text', 'wait_for_apt_lock');
    }

    it('says Ubuntu is installing its updates, once every 15 s, and when it is done', function () {
        $run = lockWait(8, '/usr/bin/python3 /usr/bin/unattended-upgrade, PID 2207');

        expect($run['out'])->toBe(implode("\n", [
            '     → Ubuntu is installing its own security updates (normal on a new server).',
            '       Waiting up to 15 minutes for it to finish...',
            '       Ubuntu is installing its own security updates (normal on a new server). Waiting… 15 s so far',
            '       Ubuntu is installing its own security updates (normal on a new server). Waiting… 30 s so far',
            "ok: Ubuntu's updates let go of the package lock (35 s)",
            'RC=0',
        ]));
    });

    it('names any other holder', function () {
        $run = lockWait(4, 'dpkg --configure -a, PID 1234');

        expect($run['out'])->toContain('→ Another package manager is running (dpkg --configure -a, PID 1234).')
            ->and($run['out'])->toContain('Another package manager is running (dpkg --configure -a, PID 1234). Waiting… 15 s so far')
            ->and($run['out'])->toContain('ok: the other package manager finished (15 s)');
    });

    it('waits for nothing when the lock is free', function () {
        expect(lockWait(0, 'dpkg')['out'])->toBe('RC=0');
    });

    it('still gives up after APT_LOCK_WAIT with the same words, and never kills the holder', function () {
        $run = lockWait(1000, 'dpkg, PID 1');

        expect($run['code'])->toBe(1)
            ->and($run['out'])->toContain('DIE: another package manager is still running after 15 minutes:')
            ->and($run['out'])->toContain('It was left alone deliberately');
        expect(installerFn('wait_for_apt_lock'))->not->toMatch('/\bkill\b|rm -f \/var\/lib\/dpkg/');
    });

    it('gives up softly when asked to, for a caller that can carry on', function () {
        $run = progressRun('
            APT_LOCK_WAIT=30; EARLY_WORK=0
            apt_lock_is_held() { return 0; }; apt_lock_holder() { printf "dpkg"; }
            ubuntu_updates_running() { return 1; }
            sleep() { SECONDS=$((SECONDS + $1)); }
            die() { echo "DIE"; exit 1; }
            wait_for_apt_lock soft || echo "SOFT=$?"', 'fmt_elapsed', 'apt_wait_text', 'wait_for_apt_lock');

        expect($run['out'])->toContain('SOFT=1')
            ->and($run['out'])->toContain('carrying on without waiting for it')
            ->and($run['out'])->not->toContain('DIE');
    });
});

describe('reports to Central', function () {
    /** The JSON bodies send_progress posted, with curl replaced by a recorder. */
    function sentBodies(string $setup): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'progress');
        $run = progressRun('
            PROGRESS_READY=1; PROGRESS_URL=http://127.0.0.1:1/report; CENTRAL_TOKEN=secret
            STEP_NO=7; STEP_TOTAL=20; CURRENT_STEP="Installing packages"; INSTALL_STARTED=1700000000
            LOG_FILE=/dev/null; FAIL_CODE=""; FAIL_FINDINGS=()
            curl() { local a; for a in "$@"; do [[ "$a" == @*/body ]] && { cat "${a#@}"; echo; } >>'.escapeshellarg($capture).'; done; return 0; }
            '.$setup.'
            wait', 'send_progress', 'json_str', 'json_text', 'one_line', 'report_log_tail', 'progress_activity');
        expect($run['code'])->toBe(0, $run['out']);

        $bodies = array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            explode("\n", (string) file_get_contents($capture)),
        )));
        unlink($capture);

        return $bodies;
    }

    it('sends exactly today\'s fields unless Central asked for activity', function () {
        $bodies = sentBodies('ACTIVITY_CODE=apt_install ACTIVITY_TEXT="setting up nginx" ACTIVITY_ELAPSED=12 send_progress installing');

        expect($bodies)->toHaveCount(1)
            ->and(array_keys($bodies[0]))->toBe(['state', 'seq', 'step', 'total', 'title', 'started_at', 'updated_at']);
    });

    it('adds the activity, as valid JSON, when Central asked for it', function () {
        $bodies = sentBodies('PROGRESS_ACTIVITY=1
            ACTIVITY_CODE=apt_lock_wait ACTIVITY_TEXT="Ubuntu is installing its own security updates \"quoted\" — Waiting… 2 min 15 s so far" ACTIVITY_ELAPSED=135 send_progress installing
            send_progress installing');

        // Both are sent in the background, so they may arrive in either order.
        $with = array_values(array_filter($bodies, fn (array $b) => isset($b['activity'])));
        $without = array_values(array_filter($bodies, fn (array $b) => ! isset($b['activity'])));

        expect($bodies)->toHaveCount(2)
            ->and($with)->toHaveCount(1)
            ->and($with[0]['activity_code'])->toBe('apt_lock_wait')
            ->and($with[0]['activity'])->toContain('"quoted"')
            ->and($with[0]['activity_elapsed'])->toBe(135)
            ->and($with[0]['step'])->toBe(7)
            // An ordinary step report carries none of it.
            ->and($without)->toHaveCount(1)
            ->and(array_keys($without[0]))->toBe(['state', 'seq', 'step', 'total', 'title', 'started_at', 'updated_at']);
    });

    it('sends one per code change at most every 15 s, and one a minute otherwise', function () {
        $bodies = sentBodies('PROGRESS_ACTIVITY=1; PT_START=1000; PT_ACT_T=0; PT_ACT_CODE=""
            PT_NOW=1010; progress_activity apt_install "a"      # first: sent
            PT_NOW=1030; progress_activity apt_install "b"      # same code, 20 s: no
            PT_NOW=1071; progress_activity apt_install "c"      # same code, 61 s: sent
            PT_NOW=1080; progress_activity apt_lock_wait "d"    # changed, 9 s: no
            PT_NOW=1087; progress_activity apt_lock_wait "e"    # changed, 16 s: sent
            PROGRESS_ACTIVITY=0
            PT_NOW=2000; progress_activity build "f"            # not asked for: no');

        $sent = array_column($bodies, 'activity');
        sort($sent);

        expect($sent)->toBe(['a', 'c', 'e']);
    });
});

describe('nothing long runs unwatched', function () {
    it('watches every run_progress command, so a slow add-apt-repository is never silent', function () {
        $run = progressRun('LOG_FILE=/dev/null
            progress_start() { echo "START $*"; }; progress_stop() { echo "STOP"; }
            run() { echo "RUN $*"; }
            run_progress "Adding the ondrej/php repository" add-apt-repository -y -n ppa:ondrej/php', 'run_progress');

        expect(explode("\n", $run['out']))->toBe([
            '     → Adding the ondrej/php repository...',
            'START adding the ondrej/php repository',
            'RUN add-apt-repository -y -n ppa:ondrej/php',
            'STOP',
            'ok: Adding the ondrej/php repository (0s)',
        ]);
    });

    it('watches the commands found silent for 20–65 s on real servers', function (string $fn, string $label) {
        $source = installerFn($fn);
        $start = strpos($source, 'progress_start "'.$label);

        expect($start)->not->toBeFalse("{$fn}() does not start the ticker")
            ->and(strpos($source, 'progress_stop', (int) $start))->not->toBeFalse("{$fn}() does not stop it");
    })->with([
        ['install_postfix', 'installing local mail (Postfix)'],
        ['install_ols_packages', 'installing ${lsphp}'],
        ['install_node_body', 'downloading fnm'],
        ['install_node_body', 'downloading Node'],
        ['install_packages', 'downloading composer'],
        ['fetch_source_body', 'downloading the panel'],
        ['setup_backend', "installing the API's PHP dependencies"],
        ['frontend_deps', "downloading the interface's packages"],
        ['build_frontend', 'building the interface'],
        ['certbot_issue', "getting a certificate from Let's Encrypt"],
        ['finish', 'checking the installation'],
    ]);
});
