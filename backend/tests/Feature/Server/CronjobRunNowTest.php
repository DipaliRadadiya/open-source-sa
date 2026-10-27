<?php

use App\Models\ActivityLog;
use App\Models\Cronjob;
use App\Models\User;
use App\Services\Server\CronjobHistory;
use App\Services\Server\CrontabManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as RealProcess;

/*
 * #4 (support list, 2026-10-27): "Run now" starts a cron job once, in the
 * background, as its own account, and its run lands in the job's history.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->base = sys_get_temp_dir().'/sv-oss-cronrun-'.getmypid();
    File::deleteDirectory($this->base);
    File::makeDirectory("{$this->base}/logs", 0755, true);
    config([
        'server.cron_d' => "{$this->base}/cron.d",
        'server.cronjob_log_dir' => "{$this->base}/logs",
        'server.cronjob_logrotate_file' => "{$this->base}/logrotate",
        'server.localtime_link' => "{$this->base}/no-localtime",
        'server.timezone_file' => "{$this->base}/timezone",
    ]);

    $this->job = Cronjob::create([
        'name' => 'Nightly report',
        'slug' => 'nightly-report',
        'username' => 'deploy',
        'command' => 'echo "report $(date +%Y)"',
        'expression' => '0 3 * * *',
        'active' => true,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->base));

/**
 * Record every command; answer each from $answers by binary name.
 *
 * @param  array<string, array{0: int, 1?: string}>  $answers
 */
function runNowFake(array $answers = []): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $answers) {
        $command = (array) $process->command;
        $runs[] = $command;
        [$exit, $output] = $answers[$command[0]] ?? [0, ''];

        return Process::result(output: $output, exitCode: $exit);
    });

    return $runs;
}

function runNowStarted(ArrayObject $runs): ?array
{
    return collect($runs)->first(fn ($command) => $command[0] === 'systemd-run');
}

it('starts the job in the background as its own account, with cron\'s environment', function () {
    $runs = runNowFake(['systemctl' => [3, "inactive\n"]]);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")
        ->assertStatus(202)
        ->assertJsonPath('message', __('cronjob.run_started'));

    $started = runNowStarted($runs);

    expect($started)->not->toBeNull()
        ->and($started)->toContain('--unit=panel-cron-nightly-report', '--uid=deploy', '--setenv=PATH=/usr/bin:/bin', '--setenv=SHELL=/bin/sh', '--property=WorkingDirectory=~', '--collect')
        ->and(array_slice($started, -3, 2))->toBe(['/bin/sh', '-c'])
        // Not %-escaped: that is crontab syntax, and the shell would see the backslash.
        ->and(end($started))->toContain('( echo "report $(date +%Y)" )')
        ->and(end($started))->toContain('--- start pid=$$ manual at')
        ->and(ActivityLog::where('type', 'cronjob')->where('action', 'ran')->exists())->toBeTrue();
});

it('makes sure the log exists before the run writes to it', function () {
    $runs = runNowFake(['systemctl' => [3, "inactive\n"]]);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")->assertStatus(202);

    $commands = collect($runs);
    $touch = $commands->search(fn ($c) => $c[0] === 'touch');
    $start = $commands->search(fn ($c) => $c[0] === 'systemd-run');

    expect($touch)->not->toBeFalse()
        ->and($commands)->toContain(['chown', 'deploy', "{$this->base}/logs/nightly-report.log"])
        ->and($touch)->toBeLessThan($start);
});

it('writes a run the history reads back as manual — the real shell line', function () {
    $runs = runNowFake(['systemctl' => [3, "inactive\n"]]);
    $this->postJson("/api/cronjobs/{$this->job->id}/run")->assertStatus(202);

    $started = runNowStarted($runs);
    $line = end($started);
    (new RealProcess(['/bin/sh', '-c', $line]))->mustRun();

    $history = app(CronjobHistory::class)->parse(file_get_contents("{$this->base}/logs/nightly-report.log"));

    expect($history)->toHaveCount(1)
        ->and($history[0])->toMatchArray(['status' => 'succeeded', 'manual' => true, 'exit_code' => 0, 'output' => 'report '.date('Y')])
        ->and($history[0]['started_at'])->not->toBeNull();
});

it("writes cron's own runs so the history can read them, failures included", function () {
    // The cron.d line, as cron would run it: user column off, `\%` back to `%`.
    $written = null;
    Process::fake(function ($process) use (&$written) {
        $command = (array) $process->command;
        if ($command[0] === 'tee' && str_ends_with($command[1], 'cron.d/nightly-report')) {
            $written = (string) $process->input;
        }

        return Process::result();
    });
    $this->job->update(['command' => 'echo "year $(date +%Y)"; exit 4']);
    app(CrontabManager::class)->write($this->job);

    $entry = trim(explode("\n", $written)[1]);
    $line = str_replace('\%', '%', preg_replace('/^(\S+\s+){5}deploy\s+/', '', $entry));
    (new RealProcess(['/bin/sh', '-c', $line]))->run();

    $history = app(CronjobHistory::class)->parse(file_get_contents("{$this->base}/logs/nightly-report.log"));

    expect($history)->toHaveCount(1)
        ->and($history[0])->toMatchArray(['status' => 'failed', 'exit_code' => 4, 'manual' => false, 'output' => 'year '.date('Y')])
        ->and($history[0]['duration_seconds'])->toBeInt();
});

it('refuses a paused job', function () {
    $runs = runNowFake();
    $this->job->update(['active' => false]);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")
        ->assertStatus(422)
        ->assertJsonPath('errors.cronjob.0', __('errors/cronjob.run_paused'));

    expect(runNowStarted($runs))->toBeNull();
});

it('refuses a job whose account no longer exists', function () {
    $runs = runNowFake(['getent' => [2, '']]);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")
        ->assertStatus(422)
        ->assertJsonPath('errors.cronjob.0', __('errors/cronjob.invalid_user'));

    expect(runNowStarted($runs))->toBeNull();
});

it('refuses while an earlier Run now is still going', function () {
    $runs = runNowFake(['systemctl' => [0, "active\n"]]);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")
        ->assertStatus(422)
        ->assertJsonPath('errors.cronjob.0', __('errors/cronjob.run_already_running'));

    expect(runNowStarted($runs))->toBeNull()
        ->and(collect($runs))->toContain(['systemctl', 'is-active', 'panel-cron-nightly-report.service']);
});

it('says "already running" when a second click wins the race for the unit', function () {
    $checks = 0;
    Process::fake(function ($process) use (&$checks) {
        $command = (array) $process->command;

        return match ($command[0]) {
            // Free when asked, taken by the time systemd-run tries.
            'systemctl' => ++$checks === 1 ? Process::result(output: "inactive\n", exitCode: 3) : Process::result(output: "active\n"),
            'systemd-run' => Process::result(errorOutput: 'Unit panel-cron-nightly-report.service was already loaded', exitCode: 1),
            default => Process::result(),
        };
    });

    $this->postJson("/api/cronjobs/{$this->job->id}/run")
        ->assertStatus(422)
        ->assertJsonPath('errors.cronjob.0', __('errors/cronjob.run_already_running'));
});

it('answers with a reference when the server will not start it, and logs that', function () {
    runNowFake(['systemctl' => [3, "inactive\n"], 'systemd-run' => [1, '']]);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")
        ->assertStatus(500)
        ->assertJsonPath('message', __('errors/cronjob.step.run_now'))
        ->assertJsonPath('code', 'cronjob_run_now')
        ->assertJsonStructure(['reference']);

    expect(ActivityLog::where('type', 'cronjob')->where('action', 'run_failed')->exists())->toBeTrue()
        ->and(ActivityLog::where('type', 'cronjob')->where('action', 'ran')->exists())->toBeFalse();
});

it('refuses a read-only user', function () {
    $runs = runNowFake();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'cronjob');
    $this->actingAs($viewer);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")->assertForbidden();

    expect(runNowStarted($runs))->toBeNull();
});

it('lets a user who manages cron jobs run one', function () {
    runNowFake(['systemctl' => [3, "inactive\n"]]);
    $manager = User::factory()->create();
    grantPermission($manager, 'cronjob', manage: true);
    $this->actingAs($manager);

    $this->postJson("/api/cronjobs/{$this->job->id}/run")->assertStatus(202);
});
