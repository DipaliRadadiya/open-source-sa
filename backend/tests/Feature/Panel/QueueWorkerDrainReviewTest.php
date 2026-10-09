<?php

use App\Services\Panel\QueueWorkerUnit;
use Illuminate\Support\Facades\Process;
use Tests\Support\QueueDrainUnit;

it('refuses TimeoutSec shorthand in the primary unit irrespective of its ordering', function (string $settings) {
    $unit = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n".$settings."\n";
    expect(fn () => QueueWorkerUnit::reconcile($unit))->toThrow(RuntimeException::class);
})->with(['infinite after' => "TimeoutStopSec=1800\nTimeoutSec=infinity", 'short after' => "TimeoutStopSec=1800\nTimeoutSec=1s", 'infinite before' => "TimeoutSec=infinity\nTimeoutStopSec=1800"]);

it('refuses stop-timeout shorthand in operator drop-ins too', function (string $value) {
    expect(fn () => QueueWorkerUnit::assertSafeOverrides("[Service]\nTimeoutSec={$value}\n"))->toThrow(RuntimeException::class);
})->with(['infinity', '1s']);

it('rejects dequoted escaped or expanded once flags on a SINGLE ExecStart', function (string $argument) {
    $unit = "[Service]\nExecStart=/usr/bin/php artisan queue:work ".$argument."\n";
    expect(fn () => QueueWorkerUnit::reconcile($unit))->toThrow(RuntimeException::class);
})->with(['bare' => '--once', 'double quoted' => '"--once"', 'single quoted' => "'--once'", 'escaped' => '--on\\ce', 'variable' => '$WORKER_FLAGS', 'braced variable' => '${WORKER_FLAGS}', 'specifier' => '%i']);

it('refuses pending daemon-reload without writing or reloading any unit', function () {
    $dir = storage_path('framework/testing/drain-stale-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $old = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    file_put_contents($path, $old);
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake(fn () => Process::result(output: QueueDrainUnit::metadata($path, pending: 'yes')));
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($old);
        Process::assertNotRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
    } finally {
        unlink($path);
        rmdir($dir);
    }
});

it('rechecks discovery after reload and refuses a newly appeared lifecycle drop-in', function () {
    $dir = storage_path('framework/testing/drain-new-dropin-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $dropIn = $dir.'/operator.conf';
    $old = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    file_put_contents($path, $old);
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake(function ($process) use ($path, $dropIn) {
        if (($process->command[1] ?? '') === 'daemon-reload') {
            file_put_contents($dropIn, "[Service]\nKillMode=control-group\n");

            return Process::result();
        }

        return Process::result(output: QueueDrainUnit::metadata($path, is_file($dropIn) ? $dropIn : ''));
    });
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($old)->and(file_get_contents($dropIn))->toBe("[Service]\nKillMode=control-group\n");
    } finally {
        if (is_file($dropIn)) {
            unlink($dropIn);
        } unlink($path);
        rmdir($dir);
    }
});

it('verifies loaded policy on updated AND idempotent units rather than just the source file', function (bool $current, string $key, string $value) {
    $dir = storage_path('framework/testing/drain-effective-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $old = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    if ($current) {
        $old = QueueWorkerUnit::reconcile($old);
    }
    file_put_contents($path, $old);
    config(['server.applications.systemd_dir' => $dir]);
    $reloaded = $current;
    Process::fake(function ($process) use ($path, $key, $value, &$reloaded) {
        if (($process->command[1] ?? '') === 'daemon-reload') {
            $reloaded = true;

            return Process::result();
        }

        return Process::result(output: QueueDrainUnit::metadata($path, overrides: $reloaded ? [$key => $value] : []));
    });
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($old);
    } finally {
        unlink($path);
        rmdir($dir);
    }
})->with([
    'updated cgroup' => [false, 'KillMode', 'control-group'], 'current cgroup' => [true, 'KillMode', 'control-group'],
    'updated noKILL' => [false, 'SendSIGKILL', 'no'], 'current infinity' => [true, 'TimeoutStopUSec', 'infinity'],
    'updated shortened bound' => [false, 'TimeoutStopUSec', '1s'], 'updated pending' => [false, 'NeedDaemonReload', 'yes'],
]);

it('does not restore over a concurrent operator edit when reload fails', function () {
    $dir = storage_path('framework/testing/drain-concurrent-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $old = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    $operator = $old."Environment=OPERATOR=updated\n";
    file_put_contents($path, $old);
    config(['server.applications.systemd_dir' => $dir]);
    $reloads = 0;
    Process::fake(function ($process) use ($path, $operator, &$reloads) {
        if (($process->command[1] ?? '') === 'daemon-reload') {
            $reloads++;
            if ($reloads === 1) {
                file_put_contents($path, $operator);
            }

            return Process::result(exitCode: $reloads === 1 ? 1 : 0);
        }

        return Process::result(output: QueueDrainUnit::metadata($path));
    });
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($operator);
    } finally {
        unlink($path);
        rmdir($dir);
    }
});

it('refuses a running worker without installed drain handlers or readable status', function (bool $readable) {
    $dir = storage_path('framework/testing/drain-running-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $old = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    file_put_contents($path, $old);
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'cat'
        ? Process::result(output: "SigCgt:\t0000000000000000\n", exitCode: $readable ? 0 : 1)
        : Process::result(output: QueueDrainUnit::metadata($path, overrides: ['MainPID' => '4242'])));
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($old);
        Process::assertNotRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
    } finally {
        unlink($path);
        rmdir($dir);
    }
})->with(['no handler' => true, 'cannot inspect' => false]);

it('accepts a running main worker only when all Laravel drain signals are caught', function () {
    $dir = storage_path('framework/testing/drain-running-ok-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    file_put_contents($path, "[Service]\nExecStart=/usr/bin/php artisan queue:work\n");
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'cat'
        ? Process::result(output: "SigCgt:\t0000000000004006\n")
        : Process::result(output: QueueDrainUnit::metadata($path, overrides: ['MainPID' => '4242'])));
    try {
        $this->artisan('panel:queue-worker')->assertSuccessful();
        Process::assertRan(fn ($process) => $process->command === ['cat', '/proc/4242/status']);
    } finally {
        unlink($path);
        rmdir($dir);
    }
});
