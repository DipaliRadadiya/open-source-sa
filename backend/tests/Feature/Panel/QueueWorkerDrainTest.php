<?php

use App\Services\Panel\QueueWorker;
use App\Services\Panel\QueueWorkerUnit;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\Support\QueueDrainUnit;

function drainFreshUnit(): string
{
    $install = (string) file_get_contents(base_path('../install.sh'));
    preg_match('/cat >\/etc\/systemd\/system\/\$\{PANEL_SLUG\}-queue\.service <<UNIT\n(.*?)\nUNIT/s', $install, $match);

    return strtr($match[1]."\n", [
        '${APP_USER}' => 'panel', '${backend}' => '/var/www/panel/backend', '${PANEL_PHP_BIN}' => '/usr/bin/php8.4',
    ]);
}

function drainOldUnit(): string
{
    return "[Unit]\nDescription=Custom panel queue\nAfter=network.target\n\n[Service]\nType=simple\nUser=custom\nGroup=custom\nWorkingDirectory=/var/www/custom/backend\nExecStart=/usr/bin/php8.4 /var/www/custom/backend/artisan queue:work --queue=emails,default --sleep=4 --tries=1 --max-time=3600\nEnvironment=APP_ENV=production\nEnvironmentFile=-/etc/custom-queue.env\nNoNewPrivileges=false\nUMask=0027\nRestart=always\nRestartSec=7\nTimeoutStopSec=20min\n# retain operator notes\n\n[Install]\nWantedBy=multi-user.target\n";
}

function drainFakeUnitMetadata(string $path, string $dropIns = '', bool $failed = false): void
{
    Process::fake(function ($process) use ($path, $dropIns, $failed) {
        return ($process->command[1] ?? '') === 'show'
            ? Process::result(output: QueueDrainUnit::metadata($path, $dropIns), exitCode: $failed ? 1 : 0)
            : Process::result();
    });
}

/** Model documented kill targets with real, unprivileged processes, NOT systemd/cgroups/sudo. */
function drainPortableProbe(string $unit, string $mode = 'drain', int $signal = SIGTERM): array
{
    $directory = storage_path('framework/testing/drain-'.uniqid());
    mkdir($directory, 0700, true);
    $process = new NativeProcess([PHP_BINARY, base_path('tests/Fixtures/queue-drain/worker.php'), $directory, $mode]);
    $process->setTimeout(10)->start();
    $pid = null;
    $children = [];
    $wait = function (callable $ready) use ($process): void {
        $deadline = microtime(true) + 6;
        while (! $ready()) {
            if (microtime(true) >= $deadline || ! $process->isRunning()) {
                throw new RuntimeException('Portable probe did not become ready: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(10000);
        }
    };

    try {
        $wait(fn () => is_file($directory.'/grandchild.pid'));
        $identity = json_decode(file_get_contents($directory.'/worker.json'), true, flags: JSON_THROW_ON_ERROR);
        $pid = $identity['pid'];
        expect($pid)->toBe($process->getPid())->and($identity['pgid'])->toBe($pid)->and(posix_getpgid($pid))->toBe($pid);
        // Independently establish that the REAL installed Laravel daemon has
        // the handlers the reconciler requires of a currently running worker.
        preg_match('/^SigCgt:\s*([0-9a-fA-F]{16})$/m', file_get_contents('/proc/'.$pid.'/status'), $caught);
        expect((int) hexdec(substr($caught[1], -8)) & 0x4006)->toBe(0x4006);
        $children = [(int) file_get_contents($directory.'/child.pid'), (int) file_get_contents($directory.'/grandchild.pid')];
        foreach ($children as $child) {
            expect(posix_getpgid($child))->toBe($pid);
        }

        if ($mode !== 'normal') {
            preg_match('/^KillMode=(\S+)$/m', $unit, $match);
            $mixed = ($match[1] ?? 'control-group') === 'mixed';
            expect(posix_kill($mixed ? $pid : -$pid, $signal))->toBeTrue();
            // Keep the child active after delivering the signal; a worker must
            // still be waiting on it, not have accepted the second job.
            usleep(50000);
        }

        if ($mode === 'deadline') {
            expect($process->isRunning())->toBeTrue();
            expect(posix_kill(-$pid, SIGKILL))->toBeTrue();
        } else {
            touch($directory.'/release');
        }

        try {
            $process->wait();
        } catch (ProcessSignaledException $e) {
            if ($mode !== 'deadline' || $e->getProcess()->getTermSignal() !== SIGKILL) {
                throw $e;
            }
        }
        $pdo = new PDO('sqlite:'.$directory.'/queue.sqlite');
        $jobs = $pdo->query('SELECT attempts, reserved_at FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result = [
            'completed' => is_file($directory.'/completed-first'),
            'first_count' => is_file($directory.'/accepted-first') ? file_get_contents($directory.'/accepted-first') : '',
            'second' => is_file($directory.'/accepted-second'),
            'child_interrupted' => is_file($directory.'/child-interrupted'),
            'child_completed' => is_file($directory.'/child-completed'),
            'exited' => is_file($directory.'/worker-exited'),
            'exit' => $process->getExitCode(), 'jobs' => $jobs,
        ];
        $pdo = null;

        // A process killed at the deadline may be an unreaped zombie; that is
        // not a running orphan. This is a process-group model, not cgroup proof.
        foreach ($children as $child) {
            $status = @file_get_contents('/proc/'.$child.'/status');
            expect($status === false || preg_match('/^State:\s+Z/m', $status) === 1)->toBeTrue();
        }

        return $result;
    } finally {
        if ($pid !== null && posix_getpgid($pid) === $pid) {
            posix_kill(-$pid, SIGKILL);
        }
        if ($process->isRunning()) {
            $process->stop(0);
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

it('fresh policy drains an accepted synchronous child instead of signalling the group', function () {
    $result = drainPortableProbe(drainFreshUnit());
    expect($result['completed'])->toBeTrue()->and($result['child_completed'])->toBeTrue()
        ->and($result['child_interrupted'])->toBeFalse()->and($result['second'])->toBeFalse()
        ->and($result['first_count'])->toBe('1')->and($result['exit'])->toBe(0)
        ->and($result['jobs'])->toBe([['attempts' => 0, 'reserved_at' => null]]);
});

it('reproduces interruption with the old control-group target model', function () {
    $result = drainPortableProbe("[Service]\nKillMode=control-group\n");
    expect($result['completed'])->toBeFalse()->and($result['child_interrupted'])->toBeTrue();
});

it('uses real Laravel TERM QUIT and INT handlers to quit after this job without reserving the next', function (int $signal) {
    $unit = QueueWorkerUnit::reconcile(drainOldUnit());
    $result = drainPortableProbe($unit, signal: $signal);
    expect($result['completed'])->toBeTrue()->and($result['second'])->toBeFalse()
        ->and($result['first_count'])->toBe('1')->and($result['exited'])->toBeTrue()
        ->and($result['jobs'])->toBe([['attempts' => 0, 'reserved_at' => null]]);
})->with(['TERM' => SIGTERM, 'QUIT' => SIGQUIT, 'INT' => SIGINT]);

it('allows normal child completion and removes exactly one reservation', function () {
    $result = drainPortableProbe(drainFreshUnit(), 'normal');
    expect($result['completed'])->toBeTrue()->and($result['second'])->toBeFalse()
        ->and($result['jobs'])->toBe([['attempts' => 0, 'reserved_at' => null]]);
});

it('retains forced whole-group cleanup at the modelled deadline with no running descendants', function () {
    $unit = QueueWorkerUnit::reconcile(drainOldUnit());
    expect($unit)->toContain('SendSIGKILL=yes', 'FinalKillSignal=SIGKILL', 'TimeoutStopFailureMode=kill', 'TimeoutStopSec=20min');
    $result = drainPortableProbe($unit, 'deadline');
    expect($result['completed'])->toBeFalse()->and($result['second'])->toBeFalse()->and($result['exited'])->toBeFalse()
        ->and($result['exit'])->toBe(137)->and($result['jobs'][0]['attempts'])->toBe(1)
        ->and($result['jobs'][1])->toBe(['attempts' => 0, 'reserved_at' => null]);
});

it('preserves custom settings queues and timeout while adding only the drain policy', function () {
    $unit = drainOldUnit();
    $desired = QueueWorkerUnit::reconcile($unit);
    foreach (explode("\n", $unit) as $line) {
        expect($desired)->toContain($line);
    }
    expect($desired)->toContain('KillMode=mixed', 'KillSignal=SIGTERM', 'RestartKillSignal=SIGTERM', 'SendSIGHUP=no')
        ->and(QueueWorkerUnit::reconcile($desired))->toBe($desired)
        ->and(QueueWorker::queuesIn($desired))->toBe(['emails', 'default']);
});

it('uses exactly the same fresh and update policy and runtime capability gate', function () {
    $fresh = drainFreshUnit();
    expect(QueueWorkerUnit::reconcile($fresh))->toBe($fresh);
    $desired = QueueWorkerUnit::reconcile("[Service]\nExecStart=/usr/bin/php8.4 /var/www/panel/backend/artisan queue:work\n");
    foreach (['KillMode=mixed', 'KillSignal=SIGTERM', 'RestartKillSignal=SIGTERM', 'SendSIGHUP=no', 'SendSIGKILL=yes', 'FinalKillSignal=SIGKILL', 'TimeoutStopFailureMode=kill', 'TimeoutStopSec=1800', QueueWorkerUnit::signalCheck('/usr/bin/php8.4')] as $line) {
        expect($fresh)->toContain($line)->and($desired)->toContain($line);
    }
});

it('fails the runtime gate if pcntl functions are disabled without starting a worker', function () {
    $check = QueueWorkerUnit::SIGNAL_CHECK;
    $supported = new NativeProcess([PHP_BINARY, '-r', $check]);
    $supported->run();
    $disabled = new NativeProcess([PHP_BINARY, '-d', 'disable_functions=pcntl_signal', '-r', $check]);
    $disabled->run();
    expect($supported->getExitCode())->toBe(0)->and($disabled->getExitCode())->toBe(1);
});

it('refuses unsafe or unsupported main/process/stop configurations', function (string $directive) {
    $unit = "[Service]\nExecStart=/usr/bin/php8.4 /var/www/panel/backend/artisan queue:work\n".$directive."\n";
    expect(fn () => QueueWorkerUnit::reconcile($unit))->toThrow(RuntimeException::class);
})->with([
    'forking' => 'Type=forking', 'oneshot' => 'Type=oneshot', 'cgroup exit' => 'ExitType=cgroup',
    'custom stop' => 'ExecStop=/bin/kill -TERM $MAINPID', 'stop post' => 'ExecStopPost=/bin/true',
    'no cleanup' => 'SendSIGKILL=no', 'HUP children' => 'SendSIGHUP=yes',
    'no deadline' => 'TimeoutStopSec=infinity', 'zero deadline' => 'TimeoutStopSec=0',
    'abort' => 'TimeoutStopFailureMode=abort', 'delegation' => 'Delegate=yes',
    'watchdog' => 'WatchdogSec=30s', 'watchdog signal' => 'WatchdogSignal=SIGKILL',
    'kill only main' => 'KillMode=process', 'kill none' => 'KillMode=none',
    'initial kill' => 'KillSignal=SIGKILL', 'restart kill' => 'RestartKillSignal=SIGKILL',
    'final TERM' => 'FinalKillSignal=SIGTERM', 'multiple start' => 'ExecStart=/usr/bin/php artisan queue:work',
    'no daemon signals' => 'ExecStart=\nExecStart=/usr/bin/php artisan queue:work --once',
    'continuation' => "Environment=X \\\nKillMode=none", 'reset precheck' => 'ExecStartPre=',
]);

it('refuses a shell wrapper instead of assuming PHP is the systemd main process', function () {
    expect(fn () => QueueWorkerUnit::reconcile("[Service]\nExecStart=/bin/sh -c 'php artisan queue:work'\n"))->toThrow(RuntimeException::class);
});

it('updates existing units idempotently without restarting and keeps unrelated drop-ins', function () {
    $dir = storage_path('framework/testing/drain-unit-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $override = $dir.'/operator.conf';
    file_put_contents($path, drainOldUnit());
    file_put_contents($override, "[Service]\nEnvironment=OPERATOR=retained\nMemoryMax=2G\n");
    config(['server.applications.systemd_dir' => $dir]);
    drainFakeUnitMetadata($path, $override);
    try {
        $this->artisan('panel:queue-worker')->assertSuccessful();
        $first = file_get_contents($path);
        expect($first)->toContain('KillMode=mixed', '--queue=emails,default', 'TimeoutStopSec=20min')
            ->and(file_get_contents($override))->toBe("[Service]\nEnvironment=OPERATOR=retained\nMemoryMax=2G\n");
        Process::assertRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
        Process::assertNotRan(fn ($process) => in_array('restart', (array) $process->command, true));
        // Facade fake records from the first call remain; measure only the
        // second invocation instead of mistaking its history for a new reload.
        $reloads = 0;
        Process::fake(function ($process) use ($path, $override, &$reloads) {
            $reloads += $process->command === ['systemctl', 'daemon-reload'] ? 1 : 0;

            return ($process->command[1] ?? '') === 'show'
                ? Process::result(output: QueueDrainUnit::metadata($path, $override))
                : Process::result();
        });
        $this->artisan('panel:queue-worker')->assertSuccessful();
        expect(file_get_contents($path))->toBe($first)->and($reloads)->toBe(0);
    } finally {
        unlink($override);
        unlink($path);
        rmdir($dir);
    }
});

it('refuses overriding or uninspectable unit metadata without changing any bytes', function (string $case) {
    $dir = storage_path('framework/testing/drain-unsafe-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $override = $dir.'/override.conf';
    file_put_contents($path, drainOldUnit());
    file_put_contents($override, "[Service]\nKillMode=control-group\n");
    config(['server.applications.systemd_dir' => $dir]);
    drainFakeUnitMetadata($case === 'fragment' ? '/other/worker.service' : $path, match ($case) {
        'override' => $override, 'missing' => $dir.'/missing.conf', default => '',
    }, $case === 'show failed');
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe(drainOldUnit());
        Process::assertNotRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
    } finally {
        unlink($override);
        unlink($path);
        rmdir($dir);
    }
})->with(['override', 'missing', 'fragment', 'show failed']);
