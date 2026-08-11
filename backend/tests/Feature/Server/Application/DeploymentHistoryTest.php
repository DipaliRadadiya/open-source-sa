<?php

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-deploy-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'depuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'App',
        'slug' => 'app',
        'domain' => 'app.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
        'repository' => 'acme/app',
        'repository_url' => 'https://github.com/acme/app.git',
        'branch' => 'main',
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
});

function runRecordedDeploy(?int $deploymentId = null): void
{
    (new DeployApplication(test()->application->id, test()->admin->id, $deploymentId))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

it('records a deploy that succeeded, with its commit', function () {
    Process::fake(function ($process) {
        return match (true) {
            in_array('rev-parse', $process->command, true) => Process::result(output: 'a1b2c3d4e5f6'),
            in_array('log', $process->command, true) => Process::result(output: "Fix the checkout bug\nAda Lovelace"),
            default => Process::result(exitCode: 0),
        };
    });

    $deployment = app(DeploymentRecorder::class)
        ->open($this->application, DeploymentTrigger::Manual, $this->admin->id);

    runRecordedDeploy($deployment->id);

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->commit_hash)->toBe('a1b2c3d4e5f6')
        ->and($deployment->shortCommit())->toBe('a1b2c3d')
        ->and($deployment->commit_message)->toBe('Fix the checkout bug')
        ->and($deployment->commit_author)->toBe('Ada Lovelace')
        ->and($deployment->finished_at)->not->toBeNull();
});

it('keeps the record of a deploy that failed, and says where', function () {
    Process::fake(function ($process) {
        // The fetch fails — the site keeps serving what it was serving, and
        // the row is the only evidence anything happened.
        return in_array('fetch', $process->command, true) || in_array('clone', $process->command, true)
            ? Process::result(errorOutput: 'fatal: repository not found', exitCode: 128)
            : Process::result(exitCode: 0);
    });

    $deployment = app(DeploymentRecorder::class)
        ->open($this->application, DeploymentTrigger::Manual, $this->admin->id);

    runRecordedDeploy($deployment->id);

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failed_step)->not->toBeNull()
        // The output is the whole point: "failed" without it is an invitation
        // to guess.
        ->and($deployment->output)->toContain('repository not found');
});

it('never stores a credential that leaked into the output', function () {
    $recorder = app(DeploymentRecorder::class);

    // Every one of these is a real shape: a git remote carrying a token, a
    // package manager printing an auth header, a provider's own token format.
    $dirty = <<<'OUT'
    fatal: could not read from https://oauth2:ghp_AbCdEfGhIjKlMnOpQrStUvWxYz012345@github.com/acme/app.git
    npm ERR! authorization: Bearer npm_9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c
    Using password=hunter2correcthorse
    GITLAB_TOKEN=glpat-XxYyZz1234567890ab
    OUT;

    $clean = $recorder->redact($dirty);

    expect($clean)->not->toContain('ghp_AbCdEfGhIjKlMnOpQrStUvWxYz012345')
        ->not->toContain('npm_9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c')
        ->not->toContain('hunter2correcthorse')
        ->not->toContain('glpat-XxYyZz1234567890ab')
        // Still readable — a redaction that eats the whole message would make
        // the feature useless.
        ->toContain('could not read from')
        ->toContain('github.com/acme/app.git');
});

it('keeps only the most recent deploys', function () {
    config(['server.deployments.keep' => 3]);

    $recorder = app(DeploymentRecorder::class);

    foreach (range(1, 6) as $ignored) {
        $recorder->open($this->application, DeploymentTrigger::Manual, $this->admin->id);
    }

    // Unbounded, this is the table that quietly fills a self-hosted SQLite
    // database — every row carries build output.
    expect(Deployment::count())->toBe(3);
});

it('does not prune another applications history', function () {
    config(['server.deployments.keep' => 2]);

    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Other',
        'slug' => 'other', 'domain' => 'other.example.com', 'site_type' => 'git',
        'serving_profile' => 'php', 'web_root' => '/', 'status' => 'active',
    ]);

    $recorder = app(DeploymentRecorder::class);
    $recorder->open($other, DeploymentTrigger::Manual, null);

    foreach (range(1, 5) as $ignored) {
        $recorder->open($this->application, DeploymentTrigger::Manual, null);
    }

    expect($other->deployments()->count())->toBe(1)
        ->and($this->application->deployments()->count())->toBe(2);
});

it('opens the row before queueing so the screen has something to show', function () {
    Queue::fake();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/deployments")
        ->assertStatus(202);

    // Without this the user clicks Deploy, sees nothing for a few seconds, and
    // clicks again.
    expect($response->json('deployment.status'))->toBe('queued')
        ->and($response->json('deployment.in_flight'))->toBeTrue();

    Queue::assertPushed(DeployApplication::class);
});

it('records a webhook deploy as System rather than inventing an actor', function () {
    $deployment = app(DeploymentRecorder::class)
        ->open($this->application, DeploymentTrigger::Webhook, null);

    expect($deployment->user_id)->toBeNull()
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Webhook);
});

it('closes the row when the worker dies outright', function () {
    $deployment = app(DeploymentRecorder::class)
        ->open($this->application, DeploymentTrigger::Manual, $this->admin->id);

    (new DeployApplication($this->application->id, $this->admin->id, $deployment->id))
        ->failed(new RuntimeException('worker died'));

    // Left running, the screen shows a spinner that never stops on a deploy
    // that is not happening.
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
});

it('keeps the output off the list and on the detail view', function () {
    $deployment = Deployment::create([
        'application_id' => $this->application->id,
        'trigger' => DeploymentTrigger::Manual,
        'status' => DeploymentStatus::Succeeded,
        'output' => 'a very long build log',
    ]);

    $list = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/deployments")
        ->assertOk();

    // Fifty deploys each carrying a full build log is a response nobody asked
    // for.
    expect($list->json('deployments.0'))->not->toHaveKey('output');

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('deployment.output', 'a very long build log');
});

it('does not let one application read another applications deploy', function () {
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Other',
        'slug' => 'other', 'domain' => 'other.example.com', 'site_type' => 'git',
        'serving_profile' => 'php', 'web_root' => '/', 'status' => 'active',
    ]);

    $foreign = Deployment::create([
        'application_id' => $other->id,
        'trigger' => DeploymentTrigger::Manual,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/deployments/{$foreign->id}")
        ->assertNotFound();
});

it('needs manage on app_deployment to start one', function () {
    Queue::fake();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_deployment');

    $this->actingAs($viewer)
        ->getJson("/api/applications/{$this->application->id}/deployments")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("/api/applications/{$this->application->id}/deployments")
        ->assertForbidden();
});

it('has no deployment screen at all on a site that cannot deploy', function () {
    $wordpress = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Blog',
        'slug' => 'blog', 'domain' => 'blog.example.com', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'web_root' => '/', 'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$wordpress->id}/deployments")
        ->assertNotFound();
});
