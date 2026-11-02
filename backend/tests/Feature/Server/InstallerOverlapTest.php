<?php

/*
 * install.sh running beside Ubuntu's first-boot updates.
 *
 * With every package installed in one block (InstallerAptPhaseTest), Ubuntu's
 * unattended-upgrades now runs while the panel is configured and built. Never
 * interrupted; instead the install copes with what it can do to us:
 *
 *  - memory: a small server waits for the updates before building; a larger
 *    one builds beside them as the process the kernel stops first, and builds
 *    again alone if it was stopped;
 *  - a restart by their needrestart hook: certbot and the Redis probe retry;
 *  - the end: "done" is said as soon as the panel works, with a note while
 *    the updates are still going.
 */

/** @return array{code: int, out: string} */
function overlapRun(string $body, string ...$functions): array
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

describe('the build beside Ubuntu\'s updates', function () {
    /**
     * build_frontend() with a fake sudo (drops `-u panel -H`) and a fake npm
     * that records the oom_score_adj it runs with. $npm is its exit code per
     * build, in order.
     */
    function buildBeside(int $ram, bool $busy, array $npm = [0]): array
    {
        $dir = sys_get_temp_dir().'/installer-build-'.bin2hex(random_bytes(4));
        mkdir($dir.'/bin', 0777, true);
        mkdir($dir.'/node');
        mkdir($dir.'/app/frontend', 0777, true);
        file_put_contents($dir.'/codes', implode("\n", $npm)."\n");
        file_put_contents($dir.'/bin/sudo', "#!/bin/sh\nshift 3\nexec \"\$@\"\n");
        file_put_contents($dir.'/node/node', "#!/bin/sh\n");
        file_put_contents($dir.'/node/npm', '#!/bin/bash
[[ " $* " == *" run build "* ]] || exit 0
echo "build adj=$(cat /proc/self/oom_score_adj)" >>'.escapeshellarg($dir.'/record').'
code=$(head -1 '.escapeshellarg($dir.'/codes').'); sed -i 1d '.escapeshellarg($dir.'/codes').'
exit "${code:-0}"
');
        foreach (['bin/sudo', 'node/node', 'node/npm'] as $f) {
            chmod($dir.'/'.$f, 0755);
        }

        $run = overlapRun('
            PATH='.escapeshellarg($dir.'/bin').':$PATH
            APP_DIR='.escapeshellarg($dir.'/app').'; APP_USER=panel; NODE_BIN='.escapeshellarg($dir.'/node/node').'
            SCHEME=https; API_HOST=api.example.test; PANEL_HOST=panel.example.test
            LOG_FILE='.escapeshellarg($dir.'/log').'; : >"$LOG_FILE"
            RECOMMENDED_RAM_MB=4096; RAM_TOLERANCE_MB=256; BUILD_MEMORY_MB=4608
            chown() { :; }
            frontend_deps() { :; }
            progress_start() { :; }; progress_stop() { :; }
            req_ram_mb() { echo '.$ram.'; }
            req_oom_kills() { echo 0; }
            journalctl() { :; }
            package_manager_busy() { '.($busy ? 'return 0' : 'return 1').'; }
            wait_for_apt_lock() { echo "WAITED for the lock"; }
            report_failure() { echo "FAILED: $1"; }
            die() { echo "DIE: $*"; exit 1; }
            build_kill_message() { echo "killed for memory"; }
            build_frontend', 'build_frontend', 'build_was_killed');

        $run['record'] = (string) @file_get_contents($dir.'/record');
        exec('rm -rf '.escapeshellarg($dir));

        return $run;
    }

    it('makes a small server wait for the updates and build alone', function () {
        $run = buildBeside(1967, busy: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain("Waiting for Ubuntu's updates to finish before building — building beside them could run out of memory on a server with 1967 MB of RAM")
            ->and($run['out'])->toContain('WAITED for the lock')
            ->and($run['record'])->not->toContain('adj=500');
    });

    it('builds beside them at once on 4 GB, as the process the kernel stops first', function () {
        $run = buildBeside(3915, busy: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->not->toContain('WAITED')
            ->and($run['record'])->toBe("build adj=500\n")
            ->and($run['out'])->toContain('ok: panel built');
    });

    it('builds with the normal score when nothing else is running', function () {
        $run = buildBeside(3915, busy: false);

        expect($run['record'])->toStartWith('build adj=')
            ->and($run['record'])->not->toContain('adj=500')
            ->and($run['out'])->not->toContain('WAITED');
    });

    it('waits and builds once more, alone, when the build was killed beside them', function () {
        $run = buildBeside(3915, busy: true, npm: [137, 0]);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain("The build ran out of memory beside Ubuntu's updates; waiting for them, then building again")
            ->and($run['out'])->toContain('WAITED for the lock')
            ->and(explode("\n", trim($run['record'])))->toHaveCount(2)
            ->and(explode("\n", trim($run['record']))[0])->toBe('build adj=500')
            ->and(explode("\n", trim($run['record']))[1])->not->toBe('build adj=500');
    });

    it('explains a second kill as it always has', function () {
        $run = buildBeside(3915, busy: true, npm: [137, 137]);

        expect($run['code'])->toBe(1)
            ->and($run['out'])->toContain('DIE: killed for memory');
    });

    it('does not rebuild a build that failed for another reason', function () {
        $run = buildBeside(3915, busy: true, npm: [1]);

        expect($run['code'])->toBe(1)
            ->and($run['out'])->toContain('FAILED: This command failed.')
            ->and($run['out'])->not->toContain('WAITED');
    });
});

describe('certbot', function () {
    /** certbot_issue() with a certbot that answers $codes in turn. */
    function certbotRun(array $codes, bool $busy): array
    {
        $codesFile = tempnam(sys_get_temp_dir(), 'certbot');
        file_put_contents($codesFile, implode("\n", $codes)."\n");

        $run = overlapRun('LOG_FILE=/dev/null; exec 9>&1
            certbot() { echo "CERTBOT $*" >&9; local c; c=$(head -1 '.escapeshellarg($codesFile).'); sed -i 1d '.escapeshellarg($codesFile).'; return "${c:-1}"; }
            package_manager_busy() { '.($busy ? 'return 0' : 'return 1').'; }
            sleep() { echo "SLEPT $1"; }
            wait_for_apt_lock() { echo "WAITED $*"; }
            progress_start() { :; }; progress_stop() { :; }
            certbot_issue --nginx -d panel.example.test && echo "ISSUED" || echo "NOT ISSUED"', 'certbot_issue');
        unlink($codesFile);

        return $run;
    }

    it('tries again 10 s later when it failed while the updates ran', function () {
        $run = certbotRun([1, 0], busy: true);

        expect(substr_count($run['out'], 'CERTBOT'))->toBe(2)
            ->and($run['out'])->toContain('SLEPT 10')
            ->and($run['out'])->toContain('ISSUED')
            ->and($run['out'])->not->toContain('NOT ISSUED');
    });

    it('waits for the updates before the third and last try', function () {
        $run = certbotRun([1, 1, 1], busy: true);

        expect(substr_count($run['out'], 'CERTBOT'))->toBe(3)
            ->and($run['out'])->toContain('WAITED soft')
            ->and($run['out'])->toContain('NOT ISSUED');
    });

    it('does not retry when nothing else was running', function () {
        $run = certbotRun([1, 0], busy: false);

        expect(substr_count($run['out'], 'CERTBOT'))->toBe(1)
            ->and($run['out'])->toContain('NOT ISSUED');
    });

    it('is what both certificate paths call', function () {
        expect(installerFn('configure_tls'))->toContain('certbot_issue "${args[@]}"')
            ->and(installerFn('configure_tls_ols'))->toContain('certbot_issue "${args[@]}"');
    });
});

it('tries the Redis password three times before falling back to the database cache', function () {
    $dir = sys_get_temp_dir().'/installer-redis-retry-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app/backend', 0777, true);
    file_put_contents($dir.'/redis.conf', "requirepass s3cret\n");
    file_put_contents($dir.'/tries', '');

    $run = overlapRun('APP_DIR='.escapeshellarg($dir.'/app').'; REDACT=(); CACHE_STORE=""
        run() { :; }
        sleep() { echo "SLEPT $1"; }
        # Restarting under the first probe, answering from the second.
        redis_password_works() { echo x >>'.escapeshellarg($dir.'/tries').'; [[ $(wc -l <'.escapeshellarg($dir.'/tries').') -ge 2 ]]; }
        configure_redis '.escapeshellarg($dir.'/redis.conf').'
        echo "CACHE=$CACHE_STORE PASSWORD=$REDIS_PASSWORD"', 'redis_default_acl', 'configure_redis');
    exec('rm -rf '.escapeshellarg($dir));

    expect($run['code'])->toBe(0, $run['out'])
        ->and($run['out'])->toContain('SLEPT 1')
        ->and($run['out'])->toContain('CACHE=redis PASSWORD=s3cret');
});

describe('the end of the install', function () {
    function finishRun(bool $updates): array
    {
        return overlapRun('APP_DIR=/nonexistent; APP_USER=panel; PANEL_PHP_BIN=php; LOG_FILE=/dev/null
            SCHEME=https; PANEL_HOST=panel.example.test; API_HOST=panel.example.test; SINGLE_HOST=1
            STACK=lemp; WEB_SERVER=nginx; LOGIN_STATUS=0; TLS_STATE=letsencrypt
            run() { :; }; sudo() { return 0; }
            progress_start() { :; }; progress_stop() { :; }
            save_install_state() { echo "STATE $1"; }; write_details_file() { :; }; release_needrestart() { :; }
            ubuntu_updates_running() { '.($updates ? 'return 0' : 'return 1').'; }
            finish', 'finish');
    }

    it('says the panel is ready while Ubuntu is still updating, and reports installed as usual', function () {
        $run = finishRun(updates: true);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('STATE installed')
            ->and($run['out'])->toContain('✅ Panel is ready. Ubuntu is still finishing its security updates in the')
            ->and($run['out'])->toContain('background (usually a few minutes). Installing new software from the')
            ->and($run['out'])->toContain("panel may take a few extra minutes until it's done.");
    });

    it('says nothing about it on a quiet server', function () {
        $run = finishRun(updates: false);

        expect($run['out'])->toContain('STATE installed')
            ->and($run['out'])->not->toContain('Panel is ready');
    });
});
