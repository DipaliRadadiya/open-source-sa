<?php

use App\Services\Panel\QueueWorkerUnit;
use Illuminate\Support\Facades\Process;
use Tests\Support\QueueDrainUnit;

it('keeps ownership mode and custom pre-start commands when reconciling a worker', function () {
    $dir = storage_path('framework/testing/drain-mode-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $unit = "[Service]\nExecStartPre=/bin/true\nExecStart=/usr/bin/php artisan queue:work --sleep=3\nUMask=0077\n";
    file_put_contents($path, $unit);
    chmod($path, 0640);
    $before = stat($path);
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake(fn ($process) => ($process->command[1] ?? '') === 'show'
        ? Process::result(output: QueueDrainUnit::metadata($path)) : Process::result());
    try {
        $this->artisan('panel:queue-worker')->assertSuccessful();
        clearstatcache(true, $path);
        $after = stat($path);
        expect($after['mode'] & 0777)->toBe(0640)->and($after['uid'])->toBe($before['uid'])->and($after['gid'])->toBe($before['gid'])
            ->and(file_get_contents($path))->toContain('ExecStartPre=/bin/true', 'UMask=0077', QueueWorkerUnit::signalCheck('/usr/bin/php'));
    } finally {
        unlink($path);
        rmdir($dir);
    }
});

it('makes dry-run inspect effective settings but never writes reloads or restarts', function () {
    $dir = storage_path('framework/testing/drain-dry-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $unit = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    file_put_contents($path, $unit);
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake(fn () => Process::result(output: QueueDrainUnit::metadata($path)));
    try {
        $this->artisan('panel:queue-worker --dry-run')->assertSuccessful();
        expect(file_get_contents($path))->toBe($unit)->and(is_file($path.'.panel-tmp'))->toBeFalse();
        Process::assertRan(fn ($process) => ($process->command[1] ?? '') === 'show');
        Process::assertNotRan(fn ($process) => in_array($process->command[1] ?? '', ['daemon-reload', 'restart'], true));
    } finally {
        unlink($path);
        rmdir($dir);
    }
});

it('reports failed daemon-reload and restores the original unit without restarting', function () {
    $dir = storage_path('framework/testing/drain-reload-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $unit = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    file_put_contents($path, $unit);
    config(['server.applications.systemd_dir' => $dir]);
    $reloads = 0;
    Process::fake(function ($process) use ($path, &$reloads) {
        if (($process->command[1] ?? '') === 'show') {
            return Process::result(output: QueueDrainUnit::metadata($path));
        }
        $reloads++;

        return Process::result(exitCode: $reloads === 1 ? 1 : 0);
    });
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($unit)->and($reloads)->toBe(2)->and(is_file($path.'.panel-tmp'))->toBeFalse();
        Process::assertNotRan(fn ($process) => in_array('restart', (array) $process->command, true));
    } finally {
        unlink($path);
        rmdir($dir);
    }
});

it('refuses missing metadata instead of silently treating an empty show response as no overrides', function () {
    $dir = storage_path('framework/testing/drain-empty-'.uniqid());
    mkdir($dir);
    $path = $dir.'/panel-queue.service';
    $unit = "[Service]\nExecStart=/usr/bin/php artisan queue:work\n";
    file_put_contents($path, $unit);
    config(['server.applications.systemd_dir' => $dir]);
    Process::fake();
    try {
        $this->artisan('panel:queue-worker')->assertFailed();
        expect(file_get_contents($path))->toBe($unit);
        Process::assertNotRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
    } finally {
        unlink($path);
        rmdir($dir);
    }
});

it('refuses a missing primary even when systemd could still have a loaded vendor unit', function () {
    config(['server.applications.systemd_dir' => storage_path('framework/testing/absent-primary-'.uniqid())]);
    Process::fake(fn () => Process::result(output: "FragmentPath=/usr/lib/systemd/system/panel-queue.service\nKillMode=control-group\n"));
    $this->artisan('panel:queue-worker')->assertFailed();
    Process::assertNothingRan();
});
