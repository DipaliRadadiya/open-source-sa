<?php

use App\Services\Panel\OlsWebAdmin;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/*
 * Finding N1 (installer batch 4, 2026-11-03): on a fresh OpenLiteSpeed install
 * Let's Encrypt timed out on every box and the panel got the self-signed
 * fallback.
 *
 * configure_ols restarted with `systemctl restart lshttpd`. The unit's stop is
 * graceful (`lswsctrl delay-stop`, KillMode=none), so the old server was still
 * draining when the start handed its listening sockets, *:7080 included, to
 * the new one. The new config wanted 127.0.0.1:7080 (S5), the new server could
 * not bind it beside the inherited socket, and it exited — over and over, for
 * up to three minutes, while certbot ran. The panel's panel:ols-webadmin did
 * the same handover with `lswsctrl restart`: there the old server simply
 * stayed, the console never moved, and every later graceful restart failed.
 *
 * Both now restart cold: stop, wait until no litespeed process is left (TERM,
 * then KILL, if it lingers), start, and check it stays up.
 */

/**
 * install.sh's restart_ols_cold and the helpers it calls, under bash with
 * systemctl/pgrep/pkill/ss/sleep stubbed. State lives in files, because the
 * helpers call some of these inside $(…).
 *
 * $linger: how many more pgrep calls after the stop still find a litespeed
 * process, or 'forever'. $endsOn: the pkill signal that makes it go.
 * $flap: the unit's MainPID changes at every look (systemd restarting it).
 *
 * @return array{code: int, out: string, log: list<string>}
 */
function olsColdRestart(int|string $linger = 0, ?string $endsOn = null, bool $flap = false, bool $dryRun = false): array
{
    $state = sys_get_temp_dir().'/ols-restart-'.bin2hex(random_bytes(4));
    mkdir($state);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($state)));

    $script = 'set -Eeuo pipefail
        trap '.escapeshellarg('echo "ERR-TRAP line $LINENO: $BASH_COMMAND"').' ERR
        DIM=""; RESET=""
        say() { printf "%s\n" "$*"; }
        die() { echo "DIE: $*"; exit 1; }
        run() { "$@"; }
        '.installerFunctions('restart_ols_cold', 'wait_for_ols_exit', 'wait_for_ols_serving', 'ols_serving_pid').'
        S='.escapeshellarg($state).'; LINGER='.escapeshellarg((string) $linger).'; ENDS_ON='.escapeshellarg((string) $endsOn).'
        FLAP='.($flap ? 1 : 0).'; DRY_RUN='.($dryRun ? 1 : 0).'; LOG_FILE=/dev/null
        OLS_START_TIMEOUT=20; OLS_SETTLE=5
        note() { echo "$*" >>"$S/log"; }
        systemctl() {
            case "$1" in
                stop) note "systemctl stop $2"; echo "$LINGER" >"$S/linger" ;;
                start) note "systemctl start $2 still-running=$(cat "$S/linger")"; touch "$S/started" ;;
                is-active) [[ -e "$S/started" ]] ;;
                show)
                    if (( FLAP )); then
                        echo $(( $(cat "$S/pid" 2>/dev/null || echo 100) + 1 )) >"$S/pid"; cat "$S/pid"
                    else
                        echo 4242
                    fi ;;
                *) note "systemctl $*" ;;
            esac
        }
        pgrep() {
            local left; left=$(cat "$S/linger" 2>/dev/null || echo 0)
            [[ "$left" == forever ]] && { echo 111; return 0; }
            (( left > 0 )) || return 1
            echo $(( left - 1 )) >"$S/linger"; echo 111
        }
        pkill() { note "pkill $*"; [[ "$1" != "$ENDS_ON" ]] || echo 0 >"$S/linger"; }
        ss() { [[ ! -e "$S/started" ]] || echo "LISTEN 0 4096 0.0.0.0:80 0.0.0.0:*"; }
        sleep() { note "sleep $1"; }
        restart_ols_cold
        echo "RESTARTED"';

    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

    $log = is_file($state.'/log') ? file($state.'/log', FILE_IGNORE_NEW_LINES) : [];

    return ['code' => $code, 'out' => implode("\n", $out), 'log' => $log ?: []];
}

describe('N1: install.sh restarts OpenLiteSpeed cold where its listeners move', function () {
    it('stops, waits while a litespeed process is still there, and starts only once it is gone', function () {
        $run = olsColdRestart(linger: 3);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('RESTARTED')
            ->and($run['log'])->toBe([
                'systemctl stop lshttpd',
                'sleep 1', 'sleep 1', 'sleep 1',
                'systemctl reset-failed lshttpd',
                'systemctl start lshttpd still-running=0',
                // Up and listening on 80, and the same process 5 s later.
                'sleep 5',
            ]);
    });

    it('stops a server still draining after the grace period with TERM, then starts', function () {
        $run = olsColdRestart(linger: 'forever', endsOn: '-TERM');

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('still finishing a graceful stop after 15 s; stopping it now')
            ->and($run['log'])->toContain('pkill -TERM -x litespeed')
            ->and($run['log'])->not->toContain('pkill -KILL -x litespeed')
            ->and(array_slice($run['log'], -2))->toBe(['systemctl start lshttpd still-running=0', 'sleep 5'])
            ->and(array_search('pkill -TERM -x litespeed', $run['log'], true))
            ->toBeLessThan(array_search('systemctl start lshttpd still-running=0', $run['log'], true));
    });

    it('falls back to KILL, and gives up with a clear message rather than starting beside it', function () {
        $killed = olsColdRestart(linger: 'forever', endsOn: '-KILL');
        $stuck = olsColdRestart(linger: 'forever');

        expect($killed['code'])->toBe(0, $killed['out'])
            ->and($killed['log'])->toContain('pkill -TERM -x litespeed', 'pkill -KILL -x litespeed', 'systemctl start lshttpd still-running=0');

        expect($stuck['code'])->toBe(1, $stuck['out'])
            ->and($stuck['out'])->toContain('DIE: OpenLiteSpeed would not stop: litespeed processes 111 are still running')
            ->and($stuck['out'])->toContain('sudo pkill -KILL -x litespeed')
            ->and($stuck['out'])->not->toContain('RESTARTED')
            ->and(implode("\n", $stuck['log']))->not->toContain('systemctl start')
            // 15 s, 15 s, then 5 s: bounded, not a hang.
            ->and(count(array_keys($stuck['log'], 'sleep 1', true)))->toBe(35);
    });

    it('stops the install when the server it started does not stay up', function () {
        // MainPID different at every look: systemd starting it over and over.
        $run = olsColdRestart(flap: true);

        expect($run['code'])->toBe(1, $run['out'])
            ->and($run['out'])->toContain('DIE: OpenLiteSpeed did not stay up on port 80 after starting')
            ->and($run['out'])->not->toContain('RESTARTED');
    });

    it('only says what it would do on a dry run', function () {
        $run = olsColdRestart(linger: 3, dryRun: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('$ systemctl stop lshttpd && (wait until no litespeed process is left) && systemctl start lshttpd')
            ->and($run['log'])->toBe([]);
    });

    it('is what configure_ols restarts with, after the WebAdmin listener has moved', function () {
        // Commands only: the comments may mention the old restart.
        $code = implode("\n", array_filter(
            explode("\n", installerFn('configure_ols')),
            fn (string $line) => ! str_starts_with(ltrim($line), '#'),
        ));

        expect($code)->not->toContain('systemctl restart lshttpd')
            ->and($code)->not->toContain('lswsctrl restart')
            ->and($code)->toContain('restart_ols_cold')
            ->and(strpos($code, 'restart_ols_cold'))->toBeGreaterThan((int) strpos($code, 'harden_ols_webadmin'))
            ->and(strpos($code, 'restart_ols_cold'))->toBeGreaterThan((int) strpos($code, 'test_ols_config'));
    });

    it('waits for the server to be up on port 80 before certbot runs', function () {
        $root = sys_get_temp_dir().'/ols-tls-'.bin2hex(random_bytes(4));
        register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

        foreach (['up' => 0, 'down' => 1] as $state => $status) {
            $script = 'set -Eeuo pipefail
                warn() { echo "warn: $*"; }; ok() { :; }; say() { :; }
                '.installerFn('configure_tls_ols').'
                wait_for_ols_serving() { echo "wait_for_ols_serving $*"; return '.$status.'; }
                certbot_issue() { echo "certbot_issue"; return 1; }
                make_self_signed_certificate() { :; }; install_ols_certificate() { :; }
                APP_DIR='.escapeshellarg($root).'; PANEL_SLUG=panel; PANEL_HOST=panel.example.com; API_HOST=api.example.com
                SINGLE_HOST=0; ADMIN_EMAIL=""; DOMAIN=panel.example.com; LOG_FILE=/dev/null; DRY_RUN=0
                configure_tls_ols';
            exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $runs[$state] = implode("\n", $out);
            $out = [];
        }

        expect($runs['up'])->toStartWith("wait_for_ols_serving 80\ncertbot_issue")
            ->and($runs['down'])->toStartWith("wait_for_ols_serving 80\nwarn: OpenLiteSpeed is not answering on port 80, so Let's Encrypt will probably not reach it\ncertbot_issue");
    });
});

/**
 * The commands the panel runs, in order, against a fake OpenLiteSpeed: pgrep
 * finds a server for $linger more looks after the stop ('forever' for one that
 * never goes), $endsOn is the pkill signal that ends it, and ss shows the
 * console on $before until the start and on $after from then on.
 */
function fakeOlsServer(int|string $linger = 0, ?string $endsOn = null, string $before = '0.0.0.0:7080', string $after = '127.0.0.1:7080', string $holder = 'litespeed'): object
{
    $server = new class
    {
        /** @var list<array<int, string>|string> */
        public array $commands = [];

        public int|string $left = 0;

        public bool $started = false;
    };

    Sleep::fake();
    Process::fake(function (PendingProcess $process) use ($server, $linger, $endsOn, $before, $after, $holder) {
        $command = $process->command;
        $server->commands[] = $command;

        return match (true) {
            $command === ['systemctl', 'stop', 'lshttpd'] => tap(Process::result(), fn () => $server->left = $linger),
            $command === ['systemctl', 'start', 'lshttpd'] => tap(Process::result(), fn () => $server->started = true),
            ($command[0] ?? '') === 'pgrep' => match (true) {
                $server->left === 'forever' => Process::result("111\n"),
                $server->left > 0 => tap(Process::result("111\n"), fn () => $server->left--),
                default => Process::result(exitCode: 1),
            },
            ($command[0] ?? '') === 'pkill' => tap(Process::result(), function () use ($server, $command, $endsOn) {
                if ($command[1] === $endsOn) {
                    $server->left = 0;
                }
            }),
            ($command[0] ?? '') === 'ss' => Process::result(sprintf(
                "LISTEN 0 4096 %s 0.0.0.0:* users:((\"%s\",pid=7,fd=22))\n",
                $server->started ? $after : $before,
                $holder,
            )),
            default => Process::result(),
        };
    });

    return $server;
}

describe('N1: panel:ols-webadmin restarts OpenLiteSpeed cold', function () {
    $shipped = "listener adminListener{\n  address               *:7080\n  secure                1\n}\n";

    function webAdminConfig(string $config): string
    {
        $dir = sys_get_temp_dir().'/ols-webadmin-'.bin2hex(random_bytes(4));
        mkdir($dir);
        register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($dir)));
        file_put_contents($dir.'/admin_config.conf', $config);
        config(['server.web_server_drivers.openlitespeed.webadmin_config' => $dir.'/admin_config.conf']);

        return $dir.'/admin_config.conf';
    }

    it('stops, waits for every litespeed process to go, then starts — never a graceful restart', function () use ($shipped) {
        webAdminConfig($shipped);
        $server = fakeOlsServer(linger: 3);

        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('now listens on 127.0.0.1:7080 only')->assertSuccessful();

        $commands = array_map(fn ($c) => implode(' ', (array) $c), $server->commands);
        $stop = array_search('systemctl stop lshttpd', $commands, true);
        $start = array_search('systemctl start lshttpd', $commands, true);
        $gone = array_key_last(array_filter(
            array_slice($commands, 0, (int) $start, true),
            fn (string $c) => $c === 'pgrep -x litespeed',
        ));

        expect($commands)->not->toContain('/usr/local/lsws/bin/lswsctrl restart')
            ->and($stop)->toBeInt()
            ->and($start)->toBeInt()
            ->and(array_slice($commands, (int) $stop + 1, 4))->toBe(array_fill(0, 4, 'pgrep -x litespeed'))
            ->and($gone)->toBeLessThan($start)
            ->and($commands)->not->toContain('pkill -TERM -x litespeed');
        Sleep::assertSleptTimes(3);
    });

    it('TERMs a server still draining after 15 s, and KILLs one that ignores that', function () use ($shipped) {
        webAdminConfig($shipped);
        $term = fakeOlsServer(linger: 'forever', endsOn: '-TERM');
        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('now listens on 127.0.0.1:7080 only')->assertSuccessful();

        expect($term->commands)->toContain(['pkill', '-TERM', '-x', 'litespeed'])
            ->and($term->commands)->not->toContain(['pkill', '-KILL', '-x', 'litespeed']);

        webAdminConfig($shipped);
        $kill = fakeOlsServer(linger: 'forever', endsOn: '-KILL');
        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('now listens on 127.0.0.1:7080 only')->assertSuccessful();

        expect($kill->commands)->toContain(['pkill', '-TERM', '-x', 'litespeed'], ['pkill', '-KILL', '-x', 'litespeed'], ['systemctl', 'start', 'lshttpd']);
    });

    it('leaves OpenLiteSpeed stopped and says so when it will not exit, rather than starting beside it', function () use ($shipped) {
        webAdminConfig($shipped);
        $server = fakeOlsServer(linger: 'forever');

        $this->artisan('panel:ols-webadmin')
            ->expectsOutputToContain('litespeed processes would not exit')
            ->assertSuccessful();

        expect($server->commands)->not->toContain(['systemctl', 'start', 'lshttpd']);
    });

    it('repairs a server whose file is bound but whose server still listens on every address', function () use ($shipped) {
        // What the graceful version of this command left behind (N1).
        $path = webAdminConfig(str_replace('*:7080', '127.0.0.1:7080', $shipped));
        $before = (string) file_get_contents($path);
        $server = fakeOlsServer();

        $this->artisan('panel:ols-webadmin', ['--dry-run' => true])
            ->expectsOutputToContain('OpenLiteSpeed would be restarted: it still listens on 0.0.0.0:7080')
            ->assertSuccessful();
        expect($server->commands)->not->toContain(['systemctl', 'stop', 'lshttpd']);

        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('now listens on 127.0.0.1:7080 only')->assertSuccessful();

        expect($server->commands)->toContain(['systemctl', 'stop', 'lshttpd'], ['systemctl', 'start', 'lshttpd'])
            ->and((string) file_get_contents($path))->toBe($before);
    });

    it('leaves a bound server alone when what holds 0.0.0.0:7080 is not OpenLiteSpeed', function () use ($shipped) {
        webAdminConfig(str_replace('*:7080', '127.0.0.1:7080', $shipped));
        $server = fakeOlsServer(holder: 'node');

        $this->artisan('panel:ols-webadmin')->expectsOutputToContain('is up to date')->assertSuccessful();

        expect($server->commands)->not->toContain(['systemctl', 'stop', 'lshttpd']);
    });

    it('does not claim the console moved when the server does not show it on 127.0.0.1', function () use ($shipped) {
        webAdminConfig($shipped);
        fakeOlsServer(after: '0.0.0.0:7080');

        $this->artisan('panel:ols-webadmin')
            ->expectsOutputToContain('OpenLiteSpeed restarted, but its console listens on 0.0.0.0:7080')
            ->doesntExpectOutputToContain('now listens on')
            ->assertSuccessful();
    });

    it('reads only OpenLiteSpeed\'s own :7080 listeners from ss', function () {
        Process::fake(["'ss' *" => Process::result(
            "LISTEN 0 4096 0.0.0.0:7080 0.0.0.0:* users:((\"litespeed\",pid=5296,fd=22),(\"litespeed\",pid=5255,fd=22))\n"
            ."LISTEN 0 4096 [::]:7080 [::]:* users:((\"node\",pid=9,fd=3))\n"
            ."LISTEN 0 4096 127.0.0.1:7080 0.0.0.0:* users:((\"litespeed\",pid=1,fd=9))\n",
        )]);

        expect(app(OlsWebAdmin::class)->liveAddresses())->toBe(['0.0.0.0:7080', '127.0.0.1:7080']);
    });
});
