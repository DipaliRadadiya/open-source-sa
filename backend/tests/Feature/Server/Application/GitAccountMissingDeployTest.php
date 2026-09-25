<?php

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitAccount;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * A git site whose account was disconnected has no credential and no URL, so
 * a deploy could only fail on `git remote add origin ""`. The Deployment
 * screen disables its button; these are the callers that are not the screen.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->systemUser = SystemUser::create([
        'username' => 'gituser', 'home_path' => '/home/gituser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->account = GitAccount::forceCreate([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octo',
        'token' => 'ghp_live', 'scopes' => ['repo'], 'last_verified_at' => now(),
    ]);

    Queue::fake();
});

function unlinkedGitApp(array $overrides = []): Application
{
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'git', 'serving_profile' => 'php', 'php_version' => '8.4',
        'status' => 'active', 'web_root' => '/',
        'git_account_id' => null,
        'repository' => 'octo/shop', 'branch' => 'main',
    ], $overrides));
}

it('refuses a deploy when the git account is missing', function () {
    $application = unlinkedGitApp();

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$application->id}/deployments")
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.git_account_missing'));

    // Refused before anything was made: no history row, no job.
    expect(Deployment::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a re-run when the git account is missing', function () {
    $application = unlinkedGitApp();
    $previous = Deployment::create([
        'application_id' => $application->id,
        'trigger' => DeploymentTrigger::Manual,
        'status' => DeploymentStatus::Failed,
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$application->id}/deployments/{$previous->id}/redeploy")
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.git_account_missing'));

    expect(Deployment::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('refuses the older deploy endpoint too when the git account is missing', function () {
    $application = unlinkedGitApp();

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$application->id}/deploy")
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.git_account_missing'));

    expect(Deployment::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('deploys once the account is linked again', function () {
    $application = unlinkedGitApp(['git_account_id' => $this->account->id]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$application->id}/deployments")
        ->assertStatus(202);

    Queue::assertPushed(DeployApplication::class);
});

it('still deploys a public-URL site, which never had an account', function () {
    // No account is normal here: deriving "missing" from a null account alone
    // would refuse every public repository.
    $application = unlinkedGitApp([
        'repository' => null,
        'repository_url' => 'https://github.com/octo/shop.git',
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$application->id}/deployments")
        ->assertStatus(202);

    Queue::assertPushed(DeployApplication::class);
});

it('ignores a push for a site whose git account is missing, as a success', function () {
    // A success, not an error: providers disable a hook that keeps failing,
    // and it would then stay off after the account was reconnected.
    $application = unlinkedGitApp();
    $application->forceFill([
        'webhook_enabled' => true,
        'webhook_provider' => 'github',
        'webhook_identifier' => 'wh-unlinked',
        'webhook_secret' => 'a-secret-of-sufficient-length',
    ])->save();

    $body = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/webhooks/deploy/wh-unlinked', [], [], [], [
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-unlinked',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'a-secret-of-sufficient-length'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)
        ->assertStatus(202)
        ->assertJson(['deployed' => false, 'reason' => 'git_account_missing']);

    expect(Deployment::count())->toBe(0);
    Queue::assertNothingPushed();
});
