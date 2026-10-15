<?php

use App\Models\PanelUpdate;
use App\Services\Panel\InstalledPanelInfo;
use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelMigration;
use App\Services\Panel\PanelPhpBinary;
use App\Services\Panel\PanelReleases;
use App\Services\Panel\ReleaseUpdateScript;
use App\Services\Panel\UpdateScript;
use Symfony\Component\Process\Process as NativeProcess;

/** Execute the rendered Bash with fixture commands ONLY, never a real host action. */
function drainUpdateFlow(string $shape, string $failure = ''): array
{
    $root = storage_path('framework/testing/drain-flow-'.uniqid());
    foreach (['bin', 'state', 'backend', 'frontend', '.git', 'shared/repo/.git', 'releases/previous/backend'] as $dir) {
        mkdir($root.'/'.$dir, 0700, true);
    }
    file_put_contents($root.'/shared/.env', "APP_KEY=fixture-only\n");
    file_put_contents($root.'/backend/.env', '');
    symlink($root.'/releases/previous', $root.'/current');
    $stub = (string) file_get_contents(base_path('tests/Fixtures/queue-drain/update-command.sh'));
    foreach (['sudo', 'git', 'systemctl', 'php', 'composer', 'npm', 'curl', 'chown', 'rm', 'ln', 'mv', 'tar', 'sleep'] as $name) {
        file_put_contents($root.'/bin/'.$name, $stub);
        chmod($root.'/bin/'.$name, 0700);
    }
    config([
        'panel_update.state_dir' => $root.'/state', 'panel_update.root' => $root,
        'panel_update.php_binary' => $root.'/bin/php', 'panel_update.node_bin_dir' => $root.'/bin',
        'database.connections.sqlite.database' => $root.'/database.sqlite',
        'panel_update.services.queue' => 'fixture-queue.service',
        'panel_update.services.php_fpm' => 'fixture-fpm.service',
        'panel_update.services.frontend' => 'fixture-frontend.service',
    ]);
    $layout = new PanelLayout($root.'/current/backend');
    if ($shape === 'release') {
        $renderer = new ReleaseUpdateScript($layout, new PanelReleases($layout), new PanelPhpBinary);
    } else {
        $installed = Mockery::mock(InstalledPanelInfo::class);
        $installed->shouldReceive('repositoryPath')->andReturn($root);
        $renderer = new UpdateScript($installed, new PanelPhpBinary);
    }
    $update = new PanelUpdate(['from_commit' => str_repeat('a', 40)]);
    $update->id = 42;
    $script = $renderer->render($update, '1.0.2');
    file_put_contents($root.'/runner.sh', $script);
    $process = new NativeProcess(['/bin/bash', $root.'/runner.sh'], $root, [
        'PATH' => $root.'/bin:/usr/bin:/bin', 'FLOW_ROOT' => $root, 'FLOW_FAIL' => $failure,
    ]);
    try {
        $process->setTimeout(10)->run();
        $log = (string) file_get_contents($root.'/commands.log');
        $state = json_decode(file_get_contents($root.'/state/update-42.json'), true, flags: JSON_THROW_ON_ERROR);

        return ['exit' => $process->getExitCode(), 'log' => $log, 'state' => $state, 'output' => $process->getOutput().$process->getErrorOutput()];
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}

it('never stops restarts restores or changes code when queue reconciliation refuses', function (string $shape) {
    $result = drainUpdateFlow($shape, 'configure');
    expect($result['exit'])->toBe(1)->and($result['state']['status'])->toBe('failed')
        ->and($result['state']['reason'])->toBe('configure_queue_worker')
        ->and($result['log'])->not->toContain('systemctl stop fixture-queue.service', 'systemctl restart fixture-queue.service', 'restore-called');
    if ($shape === 'in-place') {
        expect($result['log'])->not->toContain('checkout --force', 'artisan migrate ', 'panel:backup-database');
    }
})->with(['in-place', 'release']);

it('drains the current job before a snapshot and holds queued work until in-place health succeeds', function () {
    $result = drainUpdateFlow('in-place');
    expect($result['exit'])->toBe(0)->and($result['state']['status'])->toBe('succeeded')
        ->and($result['log'])->not->toContain('snapshot-before-drain');
    $stop = strpos($result['log'], 'systemctl stop fixture-queue.service');
    $backup = strpos($result['log'], 'panel:backup-database');
    $health = strpos($result['log'], 'curl -sS');
    $restart = strpos($result['log'], 'systemctl restart fixture-queue.service');
    expect($stop)->toBeLessThan($backup)->and($health)->toBeLessThan($restart)
        ->and(substr_count($result['log'], 'completed-current-job'))->toBe(1);
});

it('does not advance or automatically retry queue actions when the initial drain fails', function (string $shape) {
    $result = drainUpdateFlow($shape, 'stop');
    expect($result['exit'])->toBe(1)->and(substr_count($result['log'], 'systemctl stop fixture-queue.service'))->toBe(2)
        // One sudo line plus its fixture systemctl invocation; no second stop.
        ->and($result['log'])->not->toContain('systemctl restart fixture-queue.service', 'restore-called', 'artisan migrate ');
})->with(['in-place', 'release']);

it('drains before rollback code or database undo instead of restoring over an active job', function (string $shape) {
    $result = drainUpdateFlow($shape, 'migrate');
    expect($result['exit'])->toBe(1)->and($result['state']['status'])->toBe('failed');
    $stops = [];
    foreach (explode("\n", $result['log']) as $index => $line) {
        if (str_starts_with($line, 'systemctl stop fixture-queue.service')) {
            $stops[] = $index;
        }
    }
    expect(count($stops))->toBe(2)->and(substr_count($result['log'], 'completed-current-job'))->toBe(1);
    if ($shape === 'in-place') {
        expect($result['log'])->toContain('restore-called')->not->toContain('snapshot-before-drain');
        $rollbackStop = strrpos($result['log'], 'systemctl stop fixture-queue.service');
        expect($rollbackStop)->toBeLessThan(strpos($result['log'], 'checkout --force aaaaaaaaaa'))
            ->and($rollbackStop)->toBeLessThan(strpos($result['log'], 'restore-called'));
    }
})->with(['in-place', 'release']);

it('makes manual layout migration verify and drain before backup or path moves', function () {
    config(['panel_update.root' => '/fixture-panel']);
    $layout = new PanelLayout('/fixture-panel/backend');
    $plan = (new PanelMigration($layout, new PanelReleases($layout), new PanelPhpBinary))->plan('fixture');
    $commands = implode("\n", array_merge(...array_column($plan, 'commands')));
    expect(strpos($commands, 'panel:queue-worker'))->not->toBeFalse()
        ->and(strpos($commands, 'panel:queue-worker'))->toBeLessThan(strpos($commands, 'systemctl stop'))
        ->and(strpos($commands, 'systemctl stop'))->toBeLessThan(strpos($commands, 'panel:backup-database'))
        ->and(strpos($commands, 'systemctl stop'))->toBeLessThan(strpos($commands, 'find '));
});

it('never executes queued work from a failed in-place release before restoring its database', function () {
    $result = drainUpdateFlow('in-place', 'health');
    expect($result['exit'])->toBe(1)->and($result['state']['status'])->toBe('failed');
    $restart = strpos($result['log'], 'systemctl restart fixture-queue.service');
    expect($restart)->not->toBeFalse()->and($restart)->toBeGreaterThan(strpos($result['log'], 'restore-called'))
        ->and($restart)->toBeGreaterThan(strpos($result['log'], 'checkout --force aaaaaaaaaa'))
        ->and(substr_count($result['log'], 'completed-current-job'))->toBe(1);
});

it('never rewinds code or the old job ledger after attempting final queue resumption', function () {
    $result = drainUpdateFlow('in-place', 'resume');
    expect($result['log'])->toContain('completed-post-resume-job')
        ->not->toContain('restore-called', 'checkout --force aaaaaaaaaa');
    expect($result['exit'])->toBe(1)->and($result['state']['status'])->toBe('failed')
        ->and($result['state']['rolled_back'])->toBeFalse()
        ->and($result['state']['reason'])->toBe('restart_services');
});
