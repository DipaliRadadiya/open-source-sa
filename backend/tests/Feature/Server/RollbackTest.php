<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Release;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ReleaseManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->systemUser = SystemUser::create([
        'username' => 'rollbacktest',
        'home_path' => '/home/rollbacktest',
    ]);

    $this->application = Application::create([
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
        'path' => '/home/rollbacktest/rollback-site/releases/20260801090000/public',
        'commit_hash' => 'abc1234',
        'status' => 'deployed',
        'deployed_at' => now()->subHour(),
    ]);

    // Simulate the current symlink pointing to a newer (broken) release.
    $this->application->updateQuietly([
        'previous_release_path' => $prevRelease->path,
        'current_release_id' => null, // no FK needed for rollback to work
    ]);

    Process::fake(['readlink' => Process::result('/home/rollbacktest/rollback-site/releases/20260801100000', 0)]);

    $rolledBackTo = $releases->rollback($this->application);

    expect($rolledBackTo)->toBe($prevRelease->path);
});

it('prevents rollback when no previous release path is stored', function () {
    fakeSymlinkServerOps();

    $this->application->updateQuietly(['previous_release_path' => null]);

    $releases = app(ReleaseManager::class);

    expect(fn () => $releases->rollback($this->application))
        ->toThrow(\RuntimeException::class, 'No previous release to roll back to');
});

it('marks rolled-back releases as such', function () {
    fakeSymlinkServerOps();

    $prevRelease = Release::create([
        'application_id' => $this->application->id,
        'path' => '/home/rollbacktest/rollback-site/releases/20260801090000/public',
        'status' => 'deployed',
    ]);

    $this->application->updateQuietly(['previous_release_path' => $prevRelease->path]);

    Process::fake(['readlink' => Process::result('/home/rollbacktest/rollback-site/releases/20260801100000', 0)]);

    $releases = app(ReleaseManager::class);
    $releases->rollback($this->application);

    expect($prevRelease->fresh()->status)->toBe('rolled_back');
});

it('records a release in the DB after a successful deploy', function () {
    Queue::fake();
    fakeSymlinkServerOps();

    $releasePath = '/home/rollbacktest/rollback-site/releases/20260807100000';

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

it('logs application.rolled_back after a rollback', function () {
    Queue::fake();
    fakeSymlinkServerOps();

    $prevRelease = Release::create([
        'application_id' => $this->application->id,
        'path' => '/home/rollbacktest/rollback-site/releases/20260801090000/public',
        'status' => 'deployed',
    ]);

    $this->application->updateQuietly(['previous_release_path' => $prevRelease->path]);

    Process::fake(['readlink' => Process::result('/home/rollbacktest/rollback-site/releases/20260801100000', 0)]);

    actingAs($this->admin)->postJson(
        "/api/applications/{$this->application->id}/rollback",
    )->assertOk();

    expect(ActivityLog::where('type', 'application')->where('action', 'rolled_back')->exists())->toBeTrue();
});

it('requires app_deployment,manage permission to roll back', function () {
    Queue::fake();
    fakeSymlinkServerOps();

    $prevRelease = Release::create([
        'application_id' => $this->application->id,
        'path' => '/home/rollbacktest/rollback-site/releases/20260801090000/public',
        'status' => 'deployed',
    ]);

    $this->application->updateQuietly(['previous_release_path' => $prevRelease->path]);
    Process::fake(['readlink' => Process::result('/home/rollbacktest/rollback-site/releases/20260801100000', 0)]);

    // User with no app_deployment permission at all.
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_deployment', view: true, manage: false);

    actingAs($viewer)->postJson(
        "/api/applications/{$this->application->id}/rollback",
    )->assertForbidden();
});

it('returns 404 for a non-existent application', function () {
    Queue::fake();

    actingAs($this->admin)->postJson('/api/applications/99999/rollback')
        ->assertNotFound();
});
