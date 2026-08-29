<?php

use App\Models\Application;
use App\Models\GitAccount;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Re-pointing a git application at a different account.
 *
 * The account was create-time only. Disconnecting one is allowed to succeed
 * and the foreign key is `nullOnDelete`, so its applications kept a repository
 * and a branch and lost the credential that could read them — with no way
 * back. Recovery meant deleting the site and building it again, which is not a
 * recovery.
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
});

function relinkGitApp(array $overrides = []): Application
{
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'git', 'serving_profile' => 'php', 'php_version' => '8.4',
        'status' => 'active', 'web_root' => '/',
        'git_account_id' => test()->account->id,
        'repository' => 'octo/shop', 'branch' => 'main',
    ], $overrides));
}

/** GitHub answers with the branch list shape the drivers actually return. */
function relinkRepoBranches(array $names): void
{
    Http::fake([
        'api.github.com/repos/*/branches*' => Http::response(
            array_map(fn (string $n) => ['name' => $n, 'protected' => false], $names)
        ),
        '*' => Http::response([], 200),
    ]);
}

it('re-links an application whose account was disconnected', function () {
    // The case this exists for: the account is gone, the site still knows its
    // repository, and nothing could put a credential back.
    $application = relinkGitApp(['git_account_id' => null]);

    $replacement = GitAccount::forceCreate([
        'provider' => 'github', 'label' => 'New', 'identifier' => 'octo',
        'token' => 'ghp_new', 'scopes' => ['repo'], 'last_verified_at' => now(),
    ]);

    relinkRepoBranches(['main', 'develop']);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}/git-account", [
            'git_source' => 'account',
            'git_account_id' => $replacement->id,
            'repository' => 'octo/shop',
        ])
        ->assertOk()
        ->assertJsonPath('application.git_account_id', $replacement->id)
        ->assertJsonPath('application.git_account_missing', false);

    expect($application->fresh()->git_account_id)->toBe($replacement->id)
        // Not restated in the request, so it must survive untouched.
        ->and($application->fresh()->branch)->toBe('main');
});

it('refuses a credential that cannot reach the repository, without storing it', function () {
    // Storing first would move the failure to the next deploy, where it reads
    // as a deployment problem — and would leave the site pointing at something
    // worse than what it had.
    $application = relinkGitApp();

    $other = GitAccount::forceCreate([
        'provider' => 'github', 'label' => 'Personal', 'identifier' => 'someone',
        'token' => 'ghp_other', 'scopes' => ['repo'], 'last_verified_at' => now(),
    ]);

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}/git-account", [
            'git_source' => 'account',
            'git_account_id' => $other->id,
            'repository' => 'octo/shop',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('repository');

    // Unchanged: a rejected re-link must leave the record exactly as it was.
    expect($application->fresh()->git_account_id)->toBe($this->account->id);
});

it('refuses a branch the repository does not have', function () {
    $application = relinkGitApp();

    relinkRepoBranches(['main']);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}/git-account", [
            'git_source' => 'account',
            'git_account_id' => $this->account->id,
            'repository' => 'octo/shop',
            'branch' => 'nope',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch');

    expect($application->fresh()->branch)->toBe('main');
});

it('switches an account-backed site to a public URL', function () {
    $application = relinkGitApp();

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}/git-account", [
            'git_source' => 'public_url',
            'repository_url' => 'https://github.com/octo/public.git',
        ])
        ->assertOk();

    $fresh = $application->fresh();

    // Exactly one path is live at a time — leaving the old account and
    // repository set would make `remoteUrl()` prefer them and ignore the URL
    // the user just chose.
    expect($fresh->git_account_id)->toBeNull()
        ->and($fresh->repository)->toBeNull()
        ->and($fresh->repository_url)->toBe('https://github.com/octo/public.git');
});

it('disables deploy-on-push when the provider changes', function () {
    // A webhook verifies signatures against the scheme of the provider it was
    // configured for. Moving GitHub -> GitLab leaves it rejecting every
    // delivery, silently, as though the remote had stopped sending them.
    $application = relinkGitApp([
        'webhook_enabled' => true,
        'webhook_provider' => 'github',
        'webhook_identifier' => 'hook-abc',
        'webhook_secret' => 'shhh',
    ]);

    $gitlab = GitAccount::forceCreate([
        'provider' => 'gitlab', 'label' => 'Lab', 'identifier' => 'octo',
        'token' => 'glpat', 'scopes' => ['api'], 'last_verified_at' => now(),
    ]);

    Http::fake([
        '*/repository/branches*' => Http::response([['name' => 'main', 'protected' => false]]),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}/git-account", [
            'git_source' => 'account',
            'git_account_id' => $gitlab->id,
            'repository' => 'octo/shop',
        ])
        ->assertOk();

    $fresh = $application->fresh();

    expect($fresh->webhook_enabled)->toBeFalse()
        // Kept: it is the public part of the delivery URL and is unique, so
        // discarding it changes the site's address for nothing.
        ->and($fresh->webhook_identifier)->toBe('hook-abc');
});

it('reports an application whose account was disconnected', function () {
    // Nothing said so before: the site looked exactly like a public-repository
    // one until the next deploy ran `git remote add origin ""` and failed.
    $orphan = relinkGitApp(['git_account_id' => null]);

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$orphan->id}")
        ->assertOk()
        ->assertJsonPath('application.git_account_missing', true);
});

it('does not call a public-URL site broken', function () {
    // A public repository legitimately has no account. Deriving the flag from
    // a null account alone would flag every one of them.
    $public = relinkGitApp([
        'slug' => 'pub', 'domain' => 'pub.test', 'name' => 'Pub',
        'git_account_id' => null, 'repository' => null,
        'repository_url' => 'https://github.com/octo/public.git',
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$public->id}")
        ->assertOk()
        ->assertJsonPath('application.git_account_missing', false);
});

it('is not reachable on an application that is not a git site', function () {
    $wordpress = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);

    // 404 rather than 403: for this site the screen does not exist, which is a
    // different statement from "you may not".
    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$wordpress->id}/git-account", [
            'git_source' => 'account',
            'git_account_id' => $this->account->id,
            'repository' => 'octo/blog',
        ])
        ->assertNotFound();
});

it('has the new strings translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        foreach (['git.relink.repository_unreachable', 'git.relink.branch_missing'] as $key) {
            expect(__($key, [], $locale))->not->toBe($key);
        }

        expect(__('activity.application.git_account_relinked', [], $locale))
            ->not->toBe('activity.application.git_account_relinked');
    }
});
