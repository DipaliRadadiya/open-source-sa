<?php

use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * RC-13 (frontend QA, 14 Oct): GET /api/health and the admin panel-update
 * screen answered 500 ProcessTimedOutException for a minute while the
 * frontend was being rebuilt on the same box. A git command that runs out of
 * time is "could not tell", not a broken panel.
 */
it('answers ok when a git probe times out', function () {
    // A checkout with a real `.git` directory, so the git probes do run —
    // whatever this test runs from (a worktree's `.git` is a file).
    $repo = sys_get_temp_dir().'/sv-oss-health-'.bin2hex(random_bytes(4));
    mkdir($repo.'/.git', 0755, true);
    $info = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $info->shouldReceive('repositoryPath')->andReturn($repo);
    app()->instance(InstalledPanelInfo::class, $info);

    $calls = new ArrayObject;
    Process::fake(function ($process) use ($calls) {
        $calls[] = $process->command;

        throw new ProcessTimedOutException(
            new SymfonyTimedOut(new SymfonyProcess(['git']), SymfonyTimedOut::TYPE_GENERAL),
            new FakeProcessResult(exitCode: 124),
        );
    });

    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('health.status', 'ok');

    expect(count($calls))->toBeGreaterThan(0);
    @rmdir($repo.'/.git');
    @rmdir($repo);
});
