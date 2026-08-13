<?php

use App\Jobs\DeployApplication;
use App\Jobs\InstallPhpVersion;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Runtimes\PhpRuntime;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Who asked for a queued job.
 *
 * A queue worker has no authenticated user, so `ActivityLogger` defaulting to
 * `Auth::user()` recorded null for every install and deploy — the log said the
 * work happened, and that nobody had asked for it.
 *
 * Null still has to *mean* something though: a webhook deploy genuinely has no
 * user. These pin both halves, because the moment "no person did this" and "we
 * lost the person" look the same, neither can be trusted.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

it('carries the dispatching user through to the queued job', function () {
    Queue::fake();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/php/versions', ['version' => '8.3'])
        ->assertSuccessful();

    Queue::assertPushed(
        InstallPhpVersion::class,
        fn (InstallPhpVersion $job) => $job->actorId === $this->admin->id,
    );
});

it('records the dispatching user on the activity entry the job writes', function () {
    $php = Mockery::mock(PhpRuntime::class);
    $php->shouldReceive('install')->once();

    $installs = Mockery::mock(InstallTracker::class);
    $installs->shouldReceive('succeed')->once();
    // The job asks for the in-flight row so it can report apt's progress to
    // it. Null is a legitimate answer -- a job running without one simply
    // reports nothing -- and this test is about the actor, not the progress.
    $installs->shouldReceive('current')->andReturnNull();

    // Run the job the way a worker would — no authenticated user in scope.
    (new InstallPhpVersion('8.3', $this->admin->id))
        ->handle($php, app(ActivityLogger::class), $installs);

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'php',
        'action' => 'installed',
        'user_id' => $this->admin->id,
    ]);
});

it('leaves the actor null when the dispatching user has been deleted', function () {
    $doomed = User::factory()->create();
    $id = $doomed->id;
    $doomed->delete();

    $php = Mockery::mock(PhpRuntime::class);
    $php->shouldReceive('install')->once();

    $installs = Mockery::mock(InstallTracker::class);
    $installs->shouldReceive('succeed')->once();
    // The job asks for the in-flight row so it can report apt's progress to
    // it. Null is a legitimate answer -- a job running without one simply
    // reports nothing -- and this test is about the actor, not the progress.
    $installs->shouldReceive('current')->andReturnNull();

    // The install must still be recorded. Carrying the User model instead of
    // its id would have thrown ModelNotFoundException here and lost the job.
    (new InstallPhpVersion('8.3', $id))
        ->handle($php, app(ActivityLogger::class), $installs);

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'php',
        'action' => 'installed',
        'user_id' => null,
    ]);
});

it('dispatches webhook deploys with no actor', function () {
    // A git push is not a panel user. Null here is the true answer, and it has
    // to stay distinguishable from a lost one.
    $job = new DeployApplication(1, null);

    expect($job->actorId)->toBeNull();
});
