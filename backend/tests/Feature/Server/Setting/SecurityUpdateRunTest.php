<?php

use App\Enums\SecurityUpdateStatus;
use App\Jobs\InstallSecurityUpdates;
use App\Models\ActivityLog;
use App\Models\SecurityUpdateRun;
use App\Models\User;
use App\Services\Server\Settings\SecurityUpdateOutput;
use App\Services\Server\Settings\SecurityUpdateTracker;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Installing the waiting security updates on demand.
 *
 * The panel had no way to do this at all: the `updates` group wrote an
 * apt.conf.d drop-in and apt's own timer decided when anything happened. So a
 * server with a published kernel fix waited for a schedule nobody could see,
 * and the only way to patch now was SSH.
 *
 * What is actually under test is the in-between: apt takes a box-wide lock, the
 * upgrade restarts services the panel itself runs under, and the worker
 * executing it can be one of them. Every assertion here is about a run whose
 * outcome arrives late, twice, or not at all.
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

function startRun(): \Illuminate\Testing\TestResponse
{
    return test()->withHeaders(runHeaders())->postJson('/api/settings/updates/run');
}

it('queues a run and records it before the worker sees it', function () {
    Queue::fake();

    startRun()
        ->assertStatus(202)
        ->assertJsonPath('security_update.status', 'running');

    Queue::assertPushed(InstallSecurityUpdates::class);

    // Written before dispatch on purpose: started inside the job, there is a
    // window between the 202 and the worker where the run exists and nothing
    // can see it.
    expect(SecurityUpdateRun::query()->count())->toBe(1)
        ->and(SecurityUpdateRun::query()->first()->status)->toBe(SecurityUpdateStatus::Running)
        ->and(SecurityUpdateRun::query()->first()->user_id)->toBe($this->admin->id);
});

it('refuses a second run while one is open', function () {
    Queue::fake();

    startRun()->assertStatus(202);

    // 409, not 422: nothing about the request is wrong. apt's lock means a
    // second run could only wait behind the first and then repeat it.
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

    // No row, no job: a queued run that fails a minute later gives an operator
    // a red card with nothing connecting it to a missing package.
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
            // The upgrade is what creates this file, so it appears during the
            // run rather than before it.
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
        app(\App\Services\Server\Settings\SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(\App\Services\ActivityLogger::class),
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
        app(\App\Services\Server\Settings\SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(\App\Services\ActivityLogger::class),
    );

    $run->refresh();

    // The most actionable failure there is, and the one whose detail lives in
    // the dpkg log rather than this output — so it must not be flattened into
    // 'unknown'.
    expect($run->status)->toBe(SecurityUpdateStatus::Failed)
        ->and($run->reason)->toBe('dpkg')
        ->and($run->exit_code)->toBe(1)
        ->and($run->reference)->not->toBeNull();
});

it('says apt was busy rather than blaming the upgrade', function () {
    $binary = $this->binary;

    // One attempt only, so the test does not sit through the real ten minutes
    // of lock waiting.
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
        app(\App\Services\Server\Settings\SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(\App\Services\ActivityLogger::class),
    );

    // apt's own timer running the very thing being asked for is the common
    // case here, not an edge one, and "it failed" would be the wrong word.
    expect($run->refresh()->reason)->toBeIn(['locked', 'stale_lock']);
});

it('gives up on a run whose worker disappeared', function () {
    // The upgrade restarts the panel's services and the queue worker is one of
    // them. Nothing settles the row in that case, and the screen would poll a
    // run that can never advance — while every new run is refused behind it.
    $run = app(SecurityUpdateTracker::class)->start(null);

    $run->forceFill([
        'started_at' => now()->subSeconds(app(SecurityUpdateTracker::class)->staleAfter() + 60),
    ])->save();

    $status = test()->withHeaders(runHeaders())
        ->getJson('/api/settings/updates/run')
        ->assertOk();

    expect($status->json('security_update.status'))->toBe('failed')
        ->and($status->json('security_update.reason'))->toBe('worker');

    // And the refusal is lifted, or the button would be dead forever.
    Queue::fake();
    startRun()->assertStatus(202);
});

it('does not let a late worker rewrite a run age already settled', function () {
    $tracker = app(SecurityUpdateTracker::class);
    $run = $tracker->start(null);

    $run->forceFill(['started_at' => now()->subSeconds($tracker->staleAfter() + 60)])->save();
    $tracker->reconcile();

    // The job finishing afterwards must not quietly replace the account the
    // screen has already given.
    $tracker->succeed($run, 0, 2, false);

    expect($run->refresh()->status)->toBe(SecurityUpdateStatus::Failed)
        ->and($run->reason)->toBe('worker');
});

it('marks the row failed when the job itself dies', function () {
    $run = app(SecurityUpdateTracker::class)->start(null);

    (new InstallSecurityUpdates($run->getKey()))->failed(null);

    // Without this the row waits out the whole staleness window before anyone
    // is told, and the button stays refused for that long.
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
        // Watching is allowed; reading apt's output is not. It can carry
        // conffile diffs, debconf answers and mirror URLs.
        ->assertJsonPath('security_update.output', null)
        ->assertJsonPath('security_update.status', 'running');
});

it('carries the run on the settings payload, so the first paint is not blank', function () {
    Queue::fake();
    startRun()->assertStatus(202);

    test()->withHeaders(runHeaders())
        ->getJson('/api/settings')
        ->assertOk()
        // The most likely moment to open this page is just after pressing the
        // button, and waiting for the first poll would show no run at all.
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
        app(\App\Services\Server\Settings\SecurityUpdateRunner::class),
        app(SecurityUpdateTracker::class),
        app(\App\Services\ActivityLogger::class),
    );

    $invoked = collect($runs)->first(fn ($call) => in_array($binary, $call['command'], true));

    // The button sits under a toggle promising "security patches only" and a
    // count produced by apt-check. `apt-get upgrade` would upgrade everything
    // on the box — a larger promise than the card makes.
    expect($invoked)->not->toBeNull()
        ->and($invoked['command'])->toContain('-v')
        ->and(collect($runs)->pluck('command')->flatten()->all())->not->toContain('dist-upgrade')
        ->and($invoked['env'])->toBe(['DEBIAN_FRONTEND' => 'noninteractive']);
});

it('is unique box-wide, because apt is', function () {
    expect((new InstallSecurityUpdates(1))->uniqueId())->toBe('security-updates')
        // The unique lock must expire, or a dead worker black-holes every
        // future run for this id forever.
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
        // Bounded from the end: what the run said last is the part worth
        // reading, so the oldest lines are what goes.
        ->and($text)->toContain('All upgrades installed')
        ->and($text)->not->toContain('repo.example.com')
        // And never a fragment — a half line reads as a complete statement.
        ->and($text)->toStartWith('padding that goes on and on');

    // And the credential never reaches the buffer, even on a line that is not
    // the error.
    $fresh = new SecurityUpdateOutput;
    $fresh->push('Fetched https://deploy:s3cr3t@repo.example.com/ubuntu');

    expect($fresh->text())->not->toContain('s3cr3t')
        ->and($fresh->text())->toContain('repo.example.com');
});

it('reports no package count when the run did not say', function () {
    $output = new SecurityUpdateOutput;
    $output->push("Starting unattended upgrades script\nNo packages found that can be upgraded unattended\n");

    // Null, not zero: "it did not report" and "it upgraded nothing" are
    // different answers and only the second is a claim.
    expect($output->packagesUpgraded())->toBeNull();
});
