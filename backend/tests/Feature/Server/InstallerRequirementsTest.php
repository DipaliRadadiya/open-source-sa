<?php

/**
 * install.sh's requirement check, run for real under bash: check_requirements()
 * against a described server (RAM, swap, free disk, container, Docker kernel),
 * and the explanation printed when the frontend build is killed for memory.
 *
 * The facts are functions in install.sh (req_ram_mb, req_free_mb, …) so these
 * tests can describe a 1 GB server, an LXC container or a full disk without
 * being one.
 */

/** A constant out of install.sh, as the number it assigns. */
function installerNumber(string $name): int
{
    preg_match('/^'.preg_quote($name, '/').'=(\d+)$/m', installerSource(), $m);

    expect($m)->not->toBeEmpty("install.sh has no {$name}=<number>");

    return (int) $m[1];
}

/**
 * check_requirements() on a server described by $box.
 *
 * @param  array{ram?: int, swap?: int, ours?: int, var?: int, root?: int, same?: bool, container?: string, docker?: string, stack?: string, swap_mb?: ?string}  $box
 * @return array{code: int, out: string}
 */
function requirementsRun(array $box): array
{
    $box += ['ram' => 8192, 'swap' => 0, 'ours' => 0, 'var' => 80000, 'root' => 80000, 'same' => true, 'container' => 'none', 'docker' => '', 'stack' => 'lemp', 'swap_mb' => null];
    $dir = sys_get_temp_dir().'/installer-req-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $constants = '';
    foreach (['BUILD_MEMORY_MB', 'MINIMUM_SWAP_MB', 'SWAP_TOLERANCE_MB', 'MIN_RAM_MB', 'RECOMMENDED_RAM_MB', 'RAM_TOLERANCE_MB', 'MIN_DISK_MB', 'DOCKER_MIN_DISK_MB'] as $name) {
        $constants .= $name.'='.installerNumber($name)."\n";
    }

    $script = 'set -Eeuo pipefail
        trap '.escapeshellarg('echo "ERR-TRAP line $LINENO: $BASH_COMMAND"').' ERR
        '.installerFunctions('redact_log', 'log_tail', 'report_failure', 'save_install_state', 'send_progress', 'json_str', 'json_text', 'one_line', 'report_log_tail', 'requirement_fail', 'swap_to_add_mb', 'check_requirements').'
        say() { printf "%s\n" "$*"; }
        ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; warn() { echo "warn: $*"; }
        step() { STEP_NO=$((STEP_NO + 1)); CURRENT_STEP="$*"; echo "step: $*"; }
        die() { report_failure "$*"; exit 1; }
        req_ram_mb() { echo '.(int) $box['ram'].'; }
        req_swap_mb() { echo '.(int) $box['swap'].'; }
        req_our_swap_mb() { echo '.(int) $box['ours'].'; }
        req_free_mb() { case "$1" in /var) echo '.(int) $box['var'].' ;; *) echo '.(int) $box['root'].' ;; esac; }
        req_same_disk() { '.($box['same'] ? 'return 0' : 'return 1').'; }
        req_container() { echo '.escapeshellarg($box['container']).'; }
        req_docker_kernel() { printf "%s" '.escapeshellarg($box['docker']).'; }
        BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
        STEP_NO=0; STEP_TOTAL=0; CURRENT_STEP="starting up"; LOGIN_STATUS=0; INSTALL_STARTED=1700000000
        REDACT=(); PANEL_ADMIN_PASSWORD=""; CENTRAL_TOKEN=""; PROGRESS_READY=0
        INSTALL_STATE_DIR='.escapeshellarg($dir.'/state').'
        LOG_FILE='.escapeshellarg($dir.'/install.log').'
        : >"$LOG_FILE"; echo "an earlier log line" >>"$LOG_FILE"
        REQUIREMENT_FAILURES=(); SWAP_UNAVAILABLE=0
        STACK='.escapeshellarg($box['stack']).'
        '.($box['swap_mb'] === null ? 'unset PANEL_SWAP_MB' : 'PANEL_SWAP_MB='.escapeshellarg($box['swap_mb'])).'
        '.$constants.'
        check_requirements
        echo "REQUIREMENTS-PASSED SWAP_UNAVAILABLE=$SWAP_UNAVAILABLE"';

    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);
    exec('rm -rf '.escapeshellarg($dir));

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** It stopped with the requirements message, listing every line in $lines. */
function expectRefused(array $run, array $lines): void
{
    expect($run['code'])->toBe(1, $run['out'])
        ->and($run['out'])->not->toContain('REQUIREMENTS-PASSED')
        ->and($run['out'])->not->toContain('ERR-TRAP')
        ->and($run['out'])->toContain("This server does not meet the panel's requirements, so the install stopped.")
        ->and($run['out'])->toContain('Nothing has been changed on this server.')
        // Nothing changed, so no "read the log, fix it, re-run" recovery block.
        ->and($run['out'])->not->toContain('Last lines of the log')
        ->and(substr_count($run['out'], 'Nothing has been changed on this server.'))->toBe(1);

    foreach ($lines as $line) {
        expect($run['out'])->toContain($line);
    }
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/installer-req-*') ?: [] as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

describe('a server that meets the requirements', function () {
    it('passes on the :dataset stack', function (string $stack) {
        $run = requirementsRun(['ram' => 4096, 'swap' => 0, 'var' => 60000, 'stack' => $stack]);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('REQUIREMENTS-PASSED SWAP_UNAVAILABLE=0')
            ->and($run['out'])->toContain('ok: 4096 MB RAM')
            ->and($run['out'])->toContain('1024 MB of swap will be added');
    })->with(['lemp', 'lamp', 'mern', 'ols', 'docker']);

    it('passes at exactly the minimum RAM, with the swap that reaches the build budget', function () {
        $min = installerNumber('MIN_RAM_MB');
        $run = requirementsRun(['ram' => $min, 'swap' => 0]);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain((installerNumber('BUILD_MEMORY_MB') - $min).' MB of swap will be added');
    });
});

describe('memory', function () {
    it('refuses below the minimum RAM, naming both numbers', function () {
        $min = installerNumber('MIN_RAM_MB');
        $short = $min - installerNumber('RAM_TOLERANCE_MB') - 1;
        $run = requirementsRun(['ram' => $short, 'swap' => 4096]);

        expectRefused($run, ['Memory: this server has '.$short.' MB of RAM. The panel needs at least '.$min.' MB']);
    });

    it('accepts a "2 GB" server, which reports a little less than 2048 MB to the OS', function () {
        // 1967 MB on a TeamCompute 2 GB plan, measured.
        $run = requirementsRun(['ram' => 1967, 'swap' => 0]);

        expect($run['code'])->toBe(0, $run['out']);
    });

    it('refuses a 1 GB server, which could not build the panel even with swap', function () {
        // 960 MB visible on a TeamCompute 1 GB plan.
        expectRefused(requirementsRun(['ram' => 960, 'swap' => 2048]), ['Memory: this server has 960 MB of RAM.']);
    });
});

describe('swap', function () {
    it('refuses a container that cannot add the swap the build needs', function () {
        $run = requirementsRun(['ram' => 2048, 'swap' => 0, 'container' => 'lxc']);

        expectRefused($run, ['Swap: this server is a lxc container, which cannot add swap. It has 2048 MB of RAM and 0 MB of swap; building the panel needs '.installerNumber('BUILD_MEMORY_MB').' MB']);
    });

    it('lets a container with enough memory through, and skips the optional swap', function () {
        $run = requirementsRun(['ram' => 8192, 'swap' => 0, 'container' => 'lxc']);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('REQUIREMENTS-PASSED SWAP_UNAVAILABLE=1');
    });

    it('refuses when the swapfile does not fit beside the install on the same disk', function () {
        $need = installerNumber('MIN_DISK_MB');
        $add = installerNumber('BUILD_MEMORY_MB') - 2048;
        $run = requirementsRun(['ram' => 2048, 'swap' => 0, 'var' => $need + $add - 1]);

        expectRefused($run, ['Disk: '.($need + $add - 1).' MB free on /var. This install needs '.$need.' MB there plus '.$add.' MB for swap: '.($need + $add).' MB in total.']);
    });

    it('checks / for the swapfile when /var is another disk', function () {
        $run = requirementsRun(['ram' => 2048, 'swap' => 0, 'same' => false, 'root' => 500]);

        expectRefused($run, ['Disk: 500 MB free on /, where the swapfile needs '.(installerNumber('BUILD_MEMORY_MB') - 2048).' MB.']);
    });

    it('adds nothing on a re-run whose swapfile mkswap left 1 MB short', function () {
        // Found live: a re-run on a 4 GB box with our 1023 MB file promised
        // "1 MB of swap will be added", while configure_swap() added nothing.
        $run = requirementsRun(['ram' => 3915, 'swap' => 1023, 'ours' => 1023, 'var' => installerNumber('MIN_DISK_MB')]);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('ok: 3915 MB RAM + 1023 MB swap')
            ->and($run['out'])->not->toContain('will be')
            ->and($run['out'])->toContain('ok: '.installerNumber('MIN_DISK_MB').' MB free on /var');
    });

    it('counts only the growth of our own swapfile against the disk', function () {
        // A 2 GB box from the old 3072 MB budget: our 1104 MB file is replaced
        // by one that reaches the new budget, so the disk pays the difference.
        $add = installerNumber('BUILD_MEMORY_MB') - 1967;
        $grow = $add - 1104;
        $need = installerNumber('MIN_DISK_MB');
        $run = requirementsRun(['ram' => 1967, 'swap' => 1104, 'ours' => 1104, 'var' => $need + $grow - 1]);

        expectRefused($run, ['Disk: '.($need + $grow - 1).' MB free on /var. This install needs '.$need.' MB there plus '.$grow.' MB for swap: '.($need + $grow).' MB in total.']);

        $ok = requirementsRun(['ram' => 1967, 'swap' => 1104, 'ours' => 1104, 'var' => $need + $grow]);
        expect($ok['code'])->toBe(0, $ok['out'])
            ->and($ok['out'])->toContain("the panel's swapfile will be resized to {$add} MB");
    });

    it('asks nothing of swap when PANEL_SWAP_MB=0 says the operator manages it', function () {
        $run = requirementsRun(['ram' => 2048, 'swap' => 0, 'container' => 'lxc', 'swap_mb' => '0']);

        expect($run['code'])->toBe(0, $run['out']);
    });
});

describe('configure_swap()', function () {
    /** configure_swap() for real, with run() only printing what it would do. */
    $configure = function (int $ram, int $swap, int $ours, int $unavailable = 0): array {
        $script = installerFunctions('swap_to_add_mb', 'configure_swap').'
            step() { echo "step: $*"; }; ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; say() { echo "$*"; }
            run() { echo "RUN: $*"; }; swapoff() { echo "RUN: swapoff $*"; }
            req_ram_mb() { echo '.$ram.'; }; req_swap_mb() { echo '.$swap.'; }; req_our_swap_mb() { echo '.$ours.'; }
            PANEL_SWAPFILE=/nonexistent-swapfile-panel-test
            BUILD_MEMORY_MB='.installerNumber('BUILD_MEMORY_MB').'; MINIMUM_SWAP_MB='.installerNumber('MINIMUM_SWAP_MB').'; SWAP_TOLERANCE_MB='.installerNumber('SWAP_TOLERANCE_MB').'
            SWAP_UNAVAILABLE='.$unavailable.'
            unset PANEL_SWAP_MB
            configure_swap 2>&1 | grep -v "/etc/fstab" || true';
        exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

        return ['code' => $code, 'out' => implode("\n", $out)];
    };

    it('creates what the check promised', function () use ($configure) {
        $add = installerNumber('BUILD_MEMORY_MB') - 1967;
        $run = $configure(1967, 0, 0);

        expect($run['out'])->toContain("RUN: fallocate -l {$add}M /nonexistent-swapfile-panel-test");
        expect(requirementsRun(['ram' => 1967, 'swap' => 0])['out'])->toContain("{$add} MB of swap will be added");
    });

    it('leaves a swapfile mkswap left 1 MB short alone, as the check said it would', function () use ($configure) {
        $run = $configure(3915, 1023, 1023);

        expect($run['out'])->toContain('nothing to add')
            ->and($run['out'])->not->toContain('RUN:');
    });

    it('adds no swap in a container the check found cannot have any', function () use ($configure) {
        $run = $configure(8192, 0, 0, 1);

        expect($run['out'])->toContain('a container cannot add swap, and this is enough')
            ->and($run['out'])->not->toContain('RUN:');
    });
});

describe('disk', function () {
    it('asks a Docker server for more free space than the other stacks', function () {
        $docker = installerNumber('DOCKER_MIN_DISK_MB');
        $normal = installerNumber('MIN_DISK_MB');

        expect($docker)->toBeGreaterThan($normal);

        $free = $docker - 1;
        expect(requirementsRun(['var' => $free, 'stack' => 'lemp'])['code'])->toBe(0);
        expectRefused(requirementsRun(['var' => $free, 'stack' => 'docker']), ['Disk: '.$free.' MB free on /var. This install needs '.$docker.' MB there for a Docker server']);
    });

    it('still refuses a normal stack below its minimum', function () {
        expectRefused(requirementsRun(['var' => 1000, 'stack' => 'lemp']), ['Disk: 1000 MB free on /var. This install needs '.installerNumber('MIN_DISK_MB').' MB']);
    });
});

describe('docker', function () {
    it('refuses a kernel that cannot run containers, with the LXC fix', function () {
        $run = requirementsRun(['stack' => 'docker', 'container' => 'lxc', 'docker' => 'this server is not allowed to create containers (mounting in a new namespace was refused)']);

        expectRefused($run, ['Docker: this server cannot run Docker: this server is not allowed to create containers', 'security.nesting=true']);
    });

    it('warns, without refusing, that an LXC container needs nesting, which it cannot see', function () {
        $run = requirementsRun(['stack' => 'docker', 'container' => 'lxc', 'ram' => 8192]);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('warn: this is an LXC container: Docker\'s containers only start if nesting is on')
            ->and($run['out'])->toContain('security.nesting=true');
    });

    it('does not warn about nesting on a virtual machine', function () {
        $run = requirementsRun(['stack' => 'docker']);

        expect($run['out'])->toContain('ok: this server can run Docker')
            ->and($run['out'])->not->toContain('nesting');
    });

    it('tries the mounts instead of looking for overlay in /proc/filesystems', function () {
        // An overlay module not loaded yet is not listed there, and a container
        // cannot modprobe: that check refused an LXD container that ran Docker.
        $probe = installerFn('req_docker_kernel');

        expect($probe)->not->toContain('/proc/filesystems')
            ->and($probe)->not->toContain('modprobe')
            ->and($probe)->toContain('unshare --mount --propagation private mount -t overlay');
    });

    it('refuses OpenVZ outright', function () {
        expectRefused(requirementsRun(['stack' => 'docker', 'container' => 'openvz']), ['Docker: this server is an OpenVZ container, which cannot run Docker.']);
    });

    it('does not ask a non-Docker stack whether Docker can run', function () {
        $run = requirementsRun(['stack' => 'lemp', 'docker' => 'the overlay filesystem is not available']);

        expect($run['code'])->toBe(0, $run['out'])
            ->and($run['out'])->not->toContain('Docker');
    });
});

describe('several shortfalls', function () {
    it('names them all in one message', function () {
        $run = requirementsRun(['ram' => 512, 'swap' => 0, 'var' => 1000, 'stack' => 'docker', 'docker' => 'the overlay filesystem is not available']);

        expectRefused($run, ['- Memory:', '- Disk:', '- Docker:']);
    });
});

describe('the order', function () {
    it('checks the requirements after the stack is known and before anything is changed, dry runs included', function () {
        $main = installerFn('main');

        expect(strpos($main, 'check_requirements'))->toBeGreaterThan(strpos($main, 'resolve_stack'))
            ->and(strpos($main, 'check_requirements'))->toBeLessThan(strpos($main, 'if (( DRY_RUN ))'))
            ->and(strpos($main, 'check_requirements'))->toBeLessThan(strpos($main, 'start_login_status'))
            ->and(strpos($main, 'check_requirements'))->toBeLessThan(strpos($main, 'configure_swap'));
    });
});

describe('a build the kernel killed', function () {
    /** build_was_killed with this exit code, log and cgroup OOM count, no kernel log. */
    $killed = function (int $rc, string $log, ?int $oomBefore = null, int $oomNow = 0): bool {
        $dir = sys_get_temp_dir().'/installer-req-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/install.log', $log);
        $script = installerFn('build_was_killed').'
            journalctl() { return 0; }
            req_oom_kills() { echo '.$oomNow.'; }
            LOG_FILE='.escapeshellarg($dir.'/install.log').'
            build_was_killed '.$rc.' 1700000000 '.($oomBefore === null ? '' : $oomBefore);
        exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

        return $code === 0;
    };

    it('recognises SIGKILL and the shell\'s "Killed"', function () use ($killed) {
        expect($killed(137, "compiling\n"))->toBeTrue()
            ->and($killed(1, "Creating an optimized production build ...\nKilled\n"))->toBeTrue()
            ->and($killed(1, "Type error: Property 'x' does not exist\n"))->toBeFalse();
    });

    it('recognises an OOM kill npm reported only as a failure, by the cgroup\'s OOM count', function () use ($killed) {
        // npm exits 1 when its child is killed; the count is what shows it.
        expect($killed(1, "npm error command failed\n", 2, 3))->toBeTrue()
            ->and($killed(1, "npm error command failed\n", 3, 3))->toBeFalse();
    });

    it('takes the OOM count before the build and hands it to the check', function () {
        $build = installerFn('build_frontend');

        expect(strpos($build, 'oom_before=$(req_oom_kills)'))->toBeLessThan(strpos($build, 'npm --prefix "$dir" run build'))
            ->and($build)->toContain('build_was_killed "$rc" "$since" "$oom_before"');
    });

    it('keeps the installer out of the OOM killer\'s way while the build runs, so it lives to explain', function () {
        // Seen live: under a MemoryMax the cgroup OOM killer took next-build,
        // then npm, sudo and the installer's own bash, and nothing was said.
        $build = installerFn('build_frontend');
        $lower = strpos($build, 'echo -900 >"/proc/$BASHPID/oom_score_adj"');
        $reset = strpos($build, 'echo "$adj" >"/proc/$BASHPID/oom_score_adj"');
        $npm = strpos($build, 'npm --prefix "$dir" run build');
        $restore = strrpos($build, 'echo "$adj" >"/proc/$BASHPID/oom_score_adj"');

        expect($lower)->not->toBeFalse()
            // The build's subshell puts its own score back before exec'ing npm...
            ->and($lower)->toBeLessThan($reset)
            ->and($reset)->toBeLessThan($npm)
            ->and(substr($build, $reset, $npm - $reset))->toContain('exec sudo')
            // ...and the installer puts its own back once the build is over.
            ->and($restore)->toBeGreaterThan($npm)
            ->and($restore)->toBeLessThan(strpos($build, 'if (( rc != 0 ))'));
    });

    it('reads the OOM count from this process\'s own cgroup', function () {
        exec('bash -c '.escapeshellarg(installerFn('req_oom_kills').' req_oom_kills').' 2>&1', $out, $code);

        expect($code)->toBe(0)
            ->and(implode("\n", $out))->toMatch('/^\d+$/');
    });

    it('explains it with the numbers, the fix, and that a re-run is safe', function () {
        $script = installerFunctions('build_kill_message', 'req_cgroup_limit_mb').'
            req_swap_mb() { echo 2047; }
            BUILD_MEMORY_MB='.installerNumber('BUILD_MEMORY_MB').'; RECOMMENDED_RAM_MB='.installerNumber('RECOMMENDED_RAM_MB').'
            build_kill_message';
        exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);
        $text = implode("\n", $out);

        expect($code)->toBe(0, $text)
            ->and($text)->toContain('Building the panel ran out of memory, so the system stopped it ("Killed").')
            ->and($text)->toMatch('/This server has \d+ MB of RAM and 2047 MB of swap\. The build needs about '.installerNumber('BUILD_MEMORY_MB').' MB/')
            ->and($text)->toContain('sudo mkswap')
            ->and($text)->toContain('Running the installer again is safe');
    });

    it('is what the build step calls, not run()', function () {
        $build = installerFn('build_frontend');

        expect($build)->toContain('build_was_killed "$rc" "$since" "$oom_before" && die "$(build_kill_message)"')
            ->and($build)->not->toMatch('/run sudo [^\n]*\\\\\n[^\n]*NODE_OPTIONS[^\n]*\\\\\n[^\n]*run build/');
    });
});

describe('the README', function () {
    it('quotes the same numbers as the installer', function () {
        $readme = (string) file_get_contents(base_path('../README.md'));
        $gb = fn (int $mb) => rtrim(rtrim(number_format($mb / 1024, 1, '.', ''), '0'), '.').' GB';

        preg_match('/^\| Normal stacks[^\n]*$/m', $readme, $normal);
        preg_match('/^\| Docker stack[^\n]*$/m', $readme, $docker);

        expect($normal)->not->toBeEmpty('README has no "| Normal stacks" row')
            ->and($docker)->not->toBeEmpty('README has no "| Docker stack" row');

        foreach ([$normal[0], $docker[0]] as $row) {
            expect($row)->toContain($gb(installerNumber('MIN_RAM_MB')).' RAM')
                ->and($row)->toContain($gb(installerNumber('RECOMMENDED_RAM_MB')).' RAM');
        }

        expect($normal[0])->toContain($gb(installerNumber('MIN_DISK_MB')).' free on `/var`')
            ->and($docker[0])->toContain($gb(installerNumber('DOCKER_MIN_DISK_MB')).' free on `/var`')
            ->and($readme)->toContain(installerNumber('BUILD_MEMORY_MB').' MB RAM + swap');
    });
});
