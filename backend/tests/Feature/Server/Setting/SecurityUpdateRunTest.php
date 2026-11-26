<?php

use App\Enums\SecurityUpdateStatus;
use App\Jobs\InstallSecurityUpdates;
use App\Models\ActivityLog;
use App\Models\SecurityUpdateRun;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Settings\SecurityUpdateOutput;
use App\Services\Server\Settings\SecurityUpdateRunner;
use App\Services\Server\Settings\SecurityUpdateTracker;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Installing the waiting security updates on demand.
 *
 * Under test is the in-between: apt takes a box-wide lock, the upgrade restarts services the
 * panel itself runs under, and the worker executing it can be one of them. Every assertion is
 * about a run whose outcome arrives late, twice, or not at all.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-secupd-'.getmypid();
    File::deleteDirectory($this->dir);
    File::ensureDirectoryExists($this->dir);

    $this->binary = $this->dir.'/unattended-upgrade';
    File::put($this->binary, '');

    config([
        'server.security_updates.binary' => $this->binary,
        'server.reboot_required_file' => $this->dir.'/reboot-required',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function runHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function startRun(): TestResponse
{
    return test()->withHeaders(runHeaders())->postJson('/api/settings/updates/run');
}

it('queues a run and records it before the worker sees it', function () {
    Queue::fake();

    startRun()
        ->assertStatus(202)
        ->assertJsonPath('security_update.status', 'running');

    Queue::assertPushed(InstallSecurityUpdates::class);

    // Written before dispatch: started inside the job, a run would exist between the 202 and
    // the worker with nothing able to see it.
    expect(SecurityUpdateRun::query()->count())->toBe(1)
        ->and(SecurityUpdateRun::query()->first()->status)->toBe(SecurityUpdateStatus::Running)
        ->and(SecurityUpdateRun::query()->first()->user_id)->toBe($this->admin->id);
});

it('refuses a second run while one is open', function () {
    Queue::fake();

    startRun()->assertStatus(202);

    // 409, not 422: nothing about the request is wrong; a second run could only wait on apt's
    // lock and then repeat the first.
    startRun()
        ->assertStatus(409)
        ->assertJsonPath('security_update.status', 'running');

    expect(SecurityUpdateRun::query()->count())->toBe(1);
    Queue::assertPushed(InstallSecurityUpdates::class, 1);
});

it('refuses when unattended-upgrades is not installed', function () {
    Queue::fake();
    File::delete($this->binary);

    startRun()->assertStatus(422);

    // No row, no job: a run failing later would leave a red card unconnected to the missing package.
    expect(SecurityUpdateRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('denies a viewer without manage', function () {
    Queue::fake();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting', view: true, manage: false);

    test()->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->postJson('/api/settings/updates/run')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('records the packages it upgraded and that a reboot is now wanted', function () {
    $binary = $this->binary;
    $dir = $this->dir;

    Process::fake(function ($process) use ($binary, $dir) {
        if (in_array($binary, $process->command, true)) {
            // The upgrade creates this file, so it appears during the run.
            File::put($dir.'/reboot-required', '');

            return Process::result(output: implode("\n", [
                'Starting unattended upgrades script',
                'Packages that will be upgraded: curl libc6 linux-image-generic',
                'All upgrades installed',
            ]));
        }

        return Process::result(exitCode: 0);
    });

    $run = app(SecurityUpdateTracker::class)->start($this->admin->id);
    (new InstallSecurityUpdates($run->getKey(), $this->admin->id))->handle(
        app(SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(ActivityLogger::class),
    );

    $run->refresh();

    expect($run->status)->toBe(SecurityUpdateStatus::Succeeded)
        ->and($run->packages_upgraded)->toBe(3)
        ->and($run->reboot_required_after)->toBeTrue()
        ->and($run->output)->toContain('All upgrades installed')
        ->and($run->reason)->toBeNull();

    expect(ActivityLog::query()->where('action', 'security_updates_installed')->exists())->toBeTrue();
});

it('classifies a dpkg failure rather than calling it unknown', function () {
    $binary = $this->binary;

    Process::fake(function ($process) use ($binary) {
        if (in_array($binary, $process->command, true)) {
            return Process::result(
                output: "Setting up nginx (1.24.0-1ubuntu1) ...\ndpkg: error processing package nginx (--configure):",
                errorOutput: 'Sub-process /usr/bin/dpkg returned an error code (1)',
                exitCode: 1,
            );
        }

        return Process::result(exitCode: 0);
    });

    $run = app(SecurityUpdateTracker::class)->start(null);
    (new InstallSecurityUpdates($run->getKey()))->handle(
        app(SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(ActivityLogger::class),
    );

    $run->refresh();

    // The detail lives in the dpkg log, not this output, so it must not be flattened into 'unknown'.
    expect($run->status)->toBe(SecurityUpdateStatus::Failed)
        ->and($run->reason)->toBe('dpkg')
        ->and($run->exit_code)->toBe(1)
        ->and($run->reference)->not->toBeNull();
});

it('says apt was busy rather than blaming the upgrade', function () {
    $binary = $this->binary;

    // One attempt only, so the test skips the real ten minutes of lock waiting.
    config(['server.apt.lock_attempts' => 1, 'server.apt.lock_delay_ms' => 100]);

    Process::fake(function ($process) use ($binary) {
        if (in_array($binary, $process->command, true)) {
            return Process::result(
                errorOutput: 'E: Could not get lock /var/lib/dpkg/lock-frontend',
                exitCode: 1,
            );
        }

        return Process::result(exitCode: 0);
    });

    $run = app(SecurityUpdateTracker::class)->start(null);
    (new InstallSecurityUpdates($run->getKey()))->handle(
        app(SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(ActivityLogger::class),
    );

    // apt's own timer running the same upgrade is the common case, and "it failed" is the wrong word.
    expect($run->refresh()->reason)->toBeIn(['locked', 'stale_lock']);
});

it('gives up on a run whose worker disappeared', function () {
    // The upgrade restarts the panel's services, the queue worker among them. Nothing then
    // settles the row: the screen would poll a run that never advances while new runs are refused.
    $run = app(SecurityUpdateTracker::class)->start(null);

    $run->forceFill([
        'started_at' => now()->subSeconds(app(SecurityUpdateTracker::class)->staleAfter() + 60),
    ])->save();

    $status = test()->withHeaders(runHeaders())
        ->getJson('/api/settings/updates/run')
        ->assertOk();

    expect($status->json('security_update.status'))->toBe('failed')
        ->and($status->json('security_update.reason'))->toBe('worker');

    // The refusal is lifted, or the button would stay dead.
    Queue::fake();
    startRun()->assertStatus(202);
});

it('does not let a late worker rewrite a run age already settled', function () {
    $tracker = app(SecurityUpdateTracker::class);
    $run = $tracker->start(null);

    $run->forceFill(['started_at' => now()->subSeconds($tracker->staleAfter() + 60)])->save();
    $tracker->reconcile();

    // The job finishing afterwards must not replace the outcome the screen already showed.
    $tracker->succeed($run, 0, 2, false);

    expect($run->refresh()->status)->toBe(SecurityUpdateStatus::Failed)
        ->and($run->reason)->toBe('worker');
});

it('marks the row failed when the job itself dies', function () {
    $run = app(SecurityUpdateTracker::class)->start(null);

    (new InstallSecurityUpdates($run->getKey()))->failed(null);

    // Otherwise the row waits out the whole staleness window and the button stays refused.
    expect($run->refresh()->status)->toBe(SecurityUpdateStatus::Failed)
        ->and($run->reason)->toBe('worker');
});

it('withholds the captured output from a viewer who cannot manage settings', function () {
    $run = app(SecurityUpdateTracker::class)->start($this->admin->id);
    app(SecurityUpdateTracker::class)->progress($run, 'Fetched https://deploy@repo.example.com/ubuntu');

    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting', view: true, manage: false);

    test()->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->getJson('/api/settings/updates/run')
        ->assertOk()
        // Watching is allowed; reading apt's output is not: it can carry conffile diffs,
        // debconf answers and mirror URLs.
        ->assertJsonPath('security_update.output', null)
        ->assertJsonPath('security_update.status', 'running');
});

it('carries the run on the settings payload, so the first paint is not blank', function () {
    Queue::fake();
    startRun()->assertStatus(202);

    test()->withHeaders(runHeaders())
        ->getJson('/api/settings')
        ->assertOk()
        // The page is most likely opened right after pressing the button; waiting for the first
        // poll would show no run.
        ->assertJsonPath('settings.updates.security_update.status', 'running');
});

it('runs unattended-upgrades own binary, not apt-get upgrade', function () {
    $binary = $this->binary;
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($binary, $runs) {
        $runs[] = ['command' => $process->command, 'env' => $process->environment ?? []];

        return in_array($binary, $process->command, true)
            ? Process::result(output: 'All upgrades installed')
            : Process::result(exitCode: 0);
    });

    $run = app(SecurityUpdateTracker::class)->start(null);
    (new InstallSecurityUpdates($run->getKey()))->handle(
        app(SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(ActivityLogger::class),
    );

    $invoked = collect($runs)->first(fn ($call) => in_array($binary, $call['command'], true));

    // The card promises "security patches only"; `apt-get upgrade` would upgrade everything.
    expect($invoked)->not->toBeNull()
        ->and($invoked['command'])->toContain('-v')
        ->and(collect($runs)->pluck('command')->flatten()->all())->not->toContain('dist-upgrade')
        ->and($invoked['env'])->toBe(['DEBIAN_FRONTEND' => 'noninteractive']);
});

it('is unique box-wide, because apt is', function () {
    expect((new InstallSecurityUpdates(1))->uniqueId())->toBe('security-updates')
        // The unique lock must expire, or a dead worker blocks every future run for this id.
        ->and((new InstallSecurityUpdates(1))->uniqueFor())
        ->toBeGreaterThan((new InstallSecurityUpdates(1))->timeout);
});

it('keeps the output bounded and redacted', function () {
    $output = new SecurityUpdateOutput;

    $output->push("Fetched https://deploy:s3cr3t@repo.example.com/ubuntu\n");
    $output->push(str_repeat("padding that goes on and on and on and on\n", 400));
    $output->push("All upgrades installed\n");

    $text = $output->text();

    expect(strlen($text))->toBeLessThanOrEqual(SecurityUpdateOutput::MAX_BYTES)
        // Bounded from the end: the last lines are the ones worth reading, so the oldest go.
        ->and($text)->toContain('All upgrades installed')
        ->and($text)->not->toContain('repo.example.com')
        // Never a fragment: a half line reads as a complete statement.
        ->and($text)->toStartWith('padding that goes on and on');

    // The credential never reaches the buffer, even on a line that is not the error.
    $fresh = new SecurityUpdateOutput;
    $fresh->push('Fetched https://deploy:s3cr3t@repo.example.com/ubuntu');

    expect($fresh->text())->not->toContain('s3cr3t')
        ->and($fresh->text())->toContain('repo.example.com');
});

it('reports no package count when the run did not say', function () {
    $output = new SecurityUpdateOutput;
    $output->push("Starting unattended upgrades script\nNo packages found that can be upgraded unattended\n");

    // Null, not zero: "did not report" differs from "upgraded nothing", and only the second is a claim.
    expect($output->packagesUpgraded())->toBeNull();
});

describe('counting the packages of a real-sized run', function () {
    // The line comes near the start and the buffer keeps the last 8 KB, so a long run reported null.
    it('still counts them after the line has scrolled out of the buffer', function () {
        $output = new SecurityUpdateOutput;
        $output->push("Packages that will be upgraded: curl libc6 openssl\n");

        for ($i = 0; $i < 400; $i++) {
            $output->push("Setting up package-{$i} (1.0-{$i}) ...\n");
        }

        expect($output->text())->not->toContain('Packages that will be upgraded')
            ->and($output->packagesUpgraded())->toBe(3);
    });

    it('waits for the whole line when a chunk ends inside the list', function () {
        $output = new SecurityUpdateOutput;
        $output->push('Packages that will be upgraded: curl lib');
        $output->push("c6 openssl zlib1g\nSetting up curl ...\n");

        expect($output->packagesUpgraded())->toBe(4);
    });
});
