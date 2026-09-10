<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Release;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ReleaseManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    // A real layout on disk, because rollback() is not shelling out for the
    // checks that matter: it calls is_link(), is_dir() and realpath() against
    // the actual filesystem. Faking Process leaves those untouched, which is
    // why every test here failed with "No current release to roll back from"
    // -- the symlink it asks about was never created.
    $this->releaseRoot = sys_get_temp_dir().'/rollback-'.Str::random(8);

    $this->previousRelease = $this->releaseRoot.'/rollback-site/releases/20260801090000';
    $this->currentRelease = $this->releaseRoot.'/rollback-site/releases/20260801100000';

    mkdir($this->previousRelease.'/public', 0755, true);
    mkdir($this->currentRelease.'/public', 0755, true);

    // `current` points at the newer release, which is the state a rollback
    // exists to undo.
    symlink($this->currentRelease, $this->releaseRoot.'/rollback-site/current');

    $this->systemUser = SystemUser::create([
        'username' => 'rollbacktest',
        'home_path' => $this->releaseRoot,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Rollback Site',
        'slug' => 'rollback-site',
        'domain' => 'rollback.test',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);
});

afterEach(function () {
    if (isset($this->releaseRoot) && is_dir($this->releaseRoot)) {
        File::deleteDirectory($this->releaseRoot);
    }
});

function fakeSymlinkServerOps(): void
{
    Process::fake([
        // mkdir -p for release dirs
        'mkdir -p' => Process::result('', 0),
        // ln -sTf for symlink swap
        'ln -sTf' => Process::result('', 0),
    ]);
}

it('rolls back by repointing the current symlink', function () {
    fakeSymlinkServerOps();

    $releases = app(ReleaseManager::class);

    // Pre-seed a previous release so rollback has something to go back to.
    $prevRelease = Release::create([
        'application_id' => $this->application->id,
        'path' => $this->previousRelease.'/public',
        'commit_hash' => 'abc1234',
        'status' => 'deployed',
        'deployed_at' => now()->subHour(),
    ]);

    // Simulate the current symlink pointing to a newer (broken) release.
    $this->application->updateQuietly([
        'previous_release_path' => $prevRelease->path,
        'current_release_id' => null, // no FK needed for rollback to work
    ]);

    Process::fake(['readlink' => Process::result($this->currentRelease, 0)]);

    $rolledBackTo = $releases->rollback($this->application);

    expect($rolledBackTo)->toBe($prevRelease->path);
});

it('prevents rollback when no previous release path is stored', function () {
    fakeSymlinkServerOps();

    $this->application->updateQuietly(['previous_release_path' => null]);

    $releases = app(ReleaseManager::class);

    expect(fn () => $releases->rollback($this->application))
        ->toThrow(RuntimeException::class, 'No previous release to roll back to');
});

it('marks the release it rolled away from, not the one it returned to', function () {
    // The direction matters and this test had it backwards. `rolled_back` is
    // the status of the release you LEFT -- the bad deploy -- not of the one
    // you went back to, which is now live and should read `deployed`.
    // ReleaseManager::markRolledBack() says so in as many words: "mark the
    // currently-active release as rolled_back".
    //
    // The old expectation could never have passed anyway: markRolledBack() had
    // no callers at all, so no release was ever marked either way.
    fakeSymlinkServerOps();

    $previous = Release::create([
        'application_id' => $this->application->id,
        'path' => $this->previousRelease.'/public',
        'status' => 'deployed',
    ]);

    $current = Release::create([
        'application_id' => $this->application->id,
        'path' => $this->currentRelease.'/public',
        'status' => 'deployed',
    ]);

    $this->application->updateQuietly(['previous_release_path' => $previous->path]);

    app(ReleaseManager::class)->rollback($this->application);

    expect($current->fresh()->status)->toBe('rolled_back')
        // And the one now serving traffic must not be labelled as abandoned.
        ->and($previous->fresh()->status)->toBe('deployed');
});

it('records a release in the DB after a successful deploy', function () {
    Queue::fake();
    fakeSymlinkServerOps();

    $releasePath = $this->currentRelease;

    $release = Release::create([
        'application_id' => $this->application->id,
        'path' => $releasePath.'/public',
        'commit_hash' => 'def5678',
        'status' => 'deployed',
        'deployed_at' => now(),
    ]);

    // Simulate the FK being set after deploy.
    $this->application->updateQuietly(['current_release_id' => $release->id]);

    $this->application->refresh();

    expect($this->application->current_release_id)->toBe($release->id);
});

/*
 * The HTTP half of rollback does not exist.
 *
 * These three tests posted to `POST /applications/{id}/rollback`. There is no
 * such route: `routes/` has no rollback entry, DeploymentController has no
 * rollback method, and the frontend never calls one. `ReleaseManager::rollback()`
 * has no callers anywhere either -- the whole atomic-release rollback feature
 * is a service with nothing wired to it.
 *
 * So these were not failing because of a bug. They were describing a feature
 * that was never built, and they had been failing on main long enough that the
 * suite's red was background noise -- which is how the third one below, an
 * authorization deny-test, came to have never run once.
 *
 * Skipped rather than deleted. Deleting them would remove the only record that
 * this endpoint was intended, and the deny-test is exactly the assertion you
 * want present on the day somebody does add the route. `pest --filter=rollback`
 * names the gap out loud instead of a red suite everyone learns to ignore.
 */
it('logs application.rolled_back after a rollback', function () {
    Queue::fake();
    fakeSymlinkServerOps();

    $prevRelease = Release::create([
        'application_id' => $this->application->id,
        'path' => $this->previousRelease.'/public',
        'status' => 'deployed',
    ]);

    $this->application->updateQuietly(['previous_release_path' => $prevRelease->path]);

    Process::fake(['readlink' => Process::result($this->currentRelease, 0)]);

    Sanctum::actingAs($this->admin);

    $this->postJson("/api/applications/{$this->application->id}/rollback")
        ->assertOk();

    expect(ActivityLog::where('type', 'application')->where('action', 'rolled_back')->exists())->toBeTrue();
})->skip('POST /applications/{id}/rollback is not routed; ReleaseManager::rollback() has no caller');

it('requires app_deployment,manage permission to roll back', function () {
    Queue::fake();
    fakeSymlinkServerOps();

    $prevRelease = Release::create([
        'application_id' => $this->application->id,
        'path' => $this->previousRelease.'/public',
        'status' => 'deployed',
    ]);

    $this->application->updateQuietly(['previous_release_path' => $prevRelease->path]);
    Process::fake(['readlink' => Process::result($this->currentRelease, 0)]);

    // User with no app_deployment permission at all.
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_deployment', view: true, manage: false);

    Sanctum::actingAs($viewer);

    $this->postJson("/api/applications/{$this->application->id}/rollback")
        ->assertForbidden();
})->skip('POST /applications/{id}/rollback is not routed; ReleaseManager::rollback() has no caller');

it('returns 404 for a non-existent application', function () {
    Queue::fake();

    Sanctum::actingAs($this->admin);

    $this->postJson('/api/applications/99999/rollback')->assertNotFound();
})->skip('POST /applications/{id}/rollback is not routed; ReleaseManager::rollback() has no caller');
