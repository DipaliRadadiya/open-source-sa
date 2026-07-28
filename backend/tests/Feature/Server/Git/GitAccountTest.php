<?php

use App\Models\GitAccount;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // No test may reach a real provider — an unfaked call is a bug, not a
    // silent network round-trip.
    Http::preventStrayRequests();
});

function asAdmin(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/** A connected GitHub account, verification already faked. */
function connectGithub(array $overrides = []): GitAccount
{
    return GitAccount::create(array_merge([
        'provider' => 'github',
        'label' => 'Work GitHub',
        'identifier' => 'octocat',
        'token' => 'ghp_secret_value',
        'scopes' => ['repo'],
        'last_verified_at' => now(),
    ], $overrides));
}

it('lists the supported providers with their connect-form fields', function () {
    $response = $this->withHeaders(asAdmin())->getJson('/api/integrations/git/providers');

    $response->assertOk();
    $providers = collect($response->json('providers'));

    expect($providers->pluck('name')->all())->toBe(['github', 'gitlab', 'bitbucket']);

    // Bitbucket needs a workspace; GitHub does not.
    $bitbucket = $providers->firstWhere('name', 'bitbucket');
    expect(collect($bitbucket['fields'])->pluck('name')->all())->toBe(['workspace', 'token']);
    expect($bitbucket['token_help'])->not->toBeEmpty();

    $github = $providers->firstWhere('name', 'github');
    expect(collect($github['fields'])->pluck('name')->all())->toBe(['token']);
});

it('connects a github account and never returns the token', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octocat'], 200, ['x-oauth-scopes' => 'repo, read:org']),
    ]);

    $response = $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'github',
        'label' => 'Work GitHub',
        'token' => 'ghp_secret_value',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('git_account.identifier', 'octocat');
    $response->assertJsonPath('git_account.provider_title', 'GitHub');
    expect($response->json('git_account'))->not->toHaveKey('token');
    expect($response->json('git_account.scopes'))->toBe(['repo', 'read:org']);

    $account = GitAccount::first();
    expect($account->token)->toBe('ghp_secret_value');           // decrypts for us
    expect($account->getRawOriginal('token'))->not->toBe('ghp_secret_value'); // stored encrypted
    expect($account->last_verified_at)->not->toBeNull();
});

it('rejects an invalid token and stores nothing', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'github',
        'label' => 'Broken',
        'token' => 'nope',
    ])->assertStatus(422);

    expect(GitAccount::count())->toBe(0);
});

it('reports the provider as unreachable without leaking its response', function () {
    Http::fake(['api.github.com/*' => Http::response(['secret' => 'internal detail'], 500)]);

    $response = $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'github',
        'label' => 'Flaky',
        'token' => 'ghp_x',
    ]);

    $response->assertStatus(502);
    expect($response->json('reference'))->not->toBeEmpty();
    expect($response->json())->not->toHaveKey('secret');
    expect(GitAccount::count())->toBe(0);
});

it('requires a workspace for bitbucket and verifies against it', function () {
    // Missing workspace fails validation before any outbound call.
    $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'bitbucket',
        'label' => 'Team BB',
        'token' => 'atl_token',
    ])->assertStatus(422)->assertJsonValidationErrors('workspace');

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme*' => Http::response(['values' => []], 200),
    ]);

    $response = $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'bitbucket',
        'label' => 'Team BB',
        'token' => 'atl_token',
        'workspace' => 'acme',
    ]);

    $response->assertCreated();
    // A scoped token authenticates as itself, so the workspace is the identity.
    $response->assertJsonPath('git_account.identifier', 'acme');
    $response->assertJsonPath('git_account.workspace', 'acme');
});

it('rejects a self-hosted gitlab host that is not https or points at loopback', function () {
    foreach (['http://gitlab.example.com', 'https://127.0.0.1', 'https://169.254.169.254', 'https://localhost'] as $host) {
        $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
            'provider' => 'gitlab',
            'label' => 'Self hosted '.$host,
            'token' => 'glpat_x',
            'host' => $host,
        ])->assertStatus(422)->assertJsonValidationErrors('host');
    }

    expect(GitAccount::count())->toBe(0);
});

it('accepts a self-hosted gitlab instance and calls it instead of gitlab.com', function () {
    Http::fake([
        'git.example.com/api/v4/user' => Http::response(['username' => 'dev'], 200),
    ]);

    $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'gitlab',
        'label' => 'Self hosted',
        'token' => 'glpat_x',
        'host' => 'https://git.example.com',
    ])->assertCreated();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://git.example.com/api/v4/user'));
});

it('maps only allow-listed repository fields from the provider', function () {
    $account = connectGithub();

    Http::fake([
        'api.github.com/user/repos*' => Http::response([[
            'full_name' => 'octocat/hello',
            'name' => 'hello',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/octocat/hello',
            'is_admin' => true,           // hostile extras must not survive
            'owner' => ['ssn' => 'x'],
        ]], 200),
    ]);

    $response = $this->withHeaders(asAdmin())
        ->getJson("/api/integrations/git/accounts/{$account->id}/repositories");

    $response->assertOk();
    expect($response->json('repositories.0'))->toBe([
        'full_name' => 'octocat/hello',
        'name' => 'hello',
        'private' => true,
        'default_branch' => 'main',
        'url' => 'https://github.com/octocat/hello',
    ]);
});

it('lists branches for a repository', function () {
    $account = connectGithub();

    Http::fake([
        'api.github.com/repos/octocat/hello/branches*' => Http::response([
            ['name' => 'main', 'protected' => true],
            ['name' => 'develop', 'protected' => false],
        ], 200),
    ]);

    $response = $this->withHeaders(asAdmin())
        ->getJson("/api/integrations/git/accounts/{$account->id}/branches?repository=octocat/hello");

    $response->assertOk();
    expect(collect($response->json('branches'))->pluck('name')->all())->toBe(['main', 'develop']);
});

it('rejects a repository name that could escape the provider url path', function () {
    $account = connectGithub();

    $this->withHeaders(asAdmin())
        ->getJson("/api/integrations/git/accounts/{$account->id}/branches?repository=../../admin")
        ->assertStatus(422)
        ->assertJsonValidationErrors('repository');
});

it('re-verifies a stored account and refreshes its scopes', function () {
    $account = connectGithub(['scopes' => ['repo'], 'last_verified_at' => now()->subDay()]);

    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octocat'], 200, ['x-oauth-scopes' => 'repo, workflow']),
    ]);

    $response = $this->withHeaders(asAdmin())
        ->postJson("/api/integrations/git/accounts/{$account->id}/test");

    $response->assertOk();
    expect($response->json('git_account.scopes'))->toBe(['repo', 'workflow']);
    expect($account->refresh()->last_verified_at->isToday())->toBeTrue();
});

it('keeps the previous token when a rotation is rejected', function () {
    $account = connectGithub();

    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $this->withHeaders(asAdmin())
        ->putJson("/api/integrations/git/accounts/{$account->id}", ['token' => 'ghp_rotated'])
        ->assertStatus(422);

    expect($account->refresh()->token)->toBe('ghp_secret_value');
});

it('rotates the token when the new one verifies', function () {
    $account = connectGithub();

    Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'], 200)]);

    $this->withHeaders(asAdmin())
        ->putJson("/api/integrations/git/accounts/{$account->id}", ['label' => 'Renamed', 'token' => 'ghp_rotated'])
        ->assertOk()
        ->assertJsonPath('git_account.label', 'Renamed');

    expect($account->refresh()->token)->toBe('ghp_rotated');
});

it('disconnects an account', function () {
    $account = connectGithub();

    $this->withHeaders(asAdmin())
        ->deleteJson("/api/integrations/git/accounts/{$account->id}")
        ->assertOk();

    expect(GitAccount::count())->toBe(0);
});

it('rejects a duplicate label', function () {
    connectGithub();

    Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'], 200)]);

    $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'github',
        'label' => 'Work GitHub',
        'token' => 'ghp_other',
    ])->assertStatus(422)->assertJsonValidationErrors('label');
});

it('records the connection in the activity log', function () {
    Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'], 200)]);

    $this->withHeaders(asAdmin())->postJson('/api/integrations/git/accounts', [
        'provider' => 'github',
        'label' => 'Work GitHub',
        'token' => 'ghp_secret_value',
    ])->assertCreated();

    $this->withHeaders(asAdmin())->getJson('/api/activity-log')
        ->assertOk()
        ->assertJsonPath('activity_log.0.type', 'git_account')
        ->assertJsonPath('activity_log.0.action', 'connected');
});

it('denies a user without the git permission', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/integrations/git/accounts')
        ->assertForbidden();
});

it('denies connecting and disconnecting with view-only access', function () {
    $user = User::factory()->create();
    grantPermission($user, 'git', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;
    $account = connectGithub();

    // Reading is allowed...
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/integrations/git/accounts')
        ->assertOk();

    // ...mutating is not.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/integrations/git/accounts', [
            'provider' => 'github', 'label' => 'Mine', 'token' => 'ghp_x',
        ])->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/integrations/git/accounts/{$account->id}")
        ->assertForbidden();

    expect(GitAccount::count())->toBe(1);
});
