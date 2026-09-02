<?php

use App\Models\GitAccount;
use App\Models\User;
use Carbon\Carbon;
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

it('orders account and status lists without case bias', function () {
    foreach ([
        ['label' => 'Case Zebra', 'identifier' => 'zebra'],
        ['label' => 'case apple', 'identifier' => 'apple'],
        ['label' => 'CASE Banana', 'identifier' => 'banana'],
    ] as $account) {
        connectGithub($account);
    }

    $accounts = $this->withHeaders(asAdmin())
        ->getJson('/api/integrations/git/accounts')
        ->assertOk()
        ->json('git_accounts');

    Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'], 200)]);

    $statuses = $this->withHeaders(asAdmin())
        ->getJson('/api/integrations/git/accounts/status')
        ->assertOk()
        ->json('statuses');

    expect(collect($accounts)->pluck('label')->all())
        ->toBe(['case apple', 'CASE Banana', 'Case Zebra'])
        ->and(collect($statuses)->pluck('label')->all())
        ->toBe(['case apple', 'CASE Banana', 'Case Zebra']);
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

it('reports live token status per account without persisting anything', function () {
    $this->travelTo(Carbon::parse('2026-08-01 00:00:00'));

    connectGithub(['label' => 'Alive']);
    connectGithub(['label' => 'Revoked', 'token' => 'ghp_dead']);

    Http::fake([
        'api.github.com/user' => Http::sequence()
            ->push(['login' => 'octocat'], 200, ['github-authentication-token-expiration' => '2026-08-12 00:00:00 UTC'])
            ->push(['message' => 'Bad credentials'], 401),
    ]);

    $response = $this->withHeaders(asAdmin())->getJson('/api/integrations/git/accounts/status');

    $response->assertOk();
    $statuses = collect($response->json('statuses'))->keyBy('label');

    expect($statuses['Alive']['status'])->toBe('valid');
    expect($statuses['Alive']['status_title'])->toBe('Connected');
    expect($statuses['Alive']['expires_at'])->toBe('12-08-2026 00:00:00');
    expect($statuses['Alive']['expires_in_days'])->toBeGreaterThan(0);

    expect($statuses['Revoked']['status'])->toBe('invalid');
    expect($statuses['Revoked']['expires_at'])->toBeNull();

    // Nothing about the verdict is written back to the row.
    expect(GitAccount::whereNotNull('last_verified_at')->count())->toBe(2); // unchanged from creation
});

it('reports unknown rather than invalid when the provider is unreachable', function () {
    connectGithub();

    Http::fake(['api.github.com/*' => Http::response(['message' => 'oops'], 500)]);

    $response = $this->withHeaders(asAdmin())->getJson('/api/integrations/git/accounts/status');

    $response->assertOk();
    // A provider outage must not accuse a healthy token.
    $response->assertJsonPath('statuses.0.status', 'unknown');
    $response->assertJsonPath('statuses.0.status_title', 'Could not check');
});

it('keeps one dead account from breaking the other rows', function () {
    connectGithub(['label' => 'AAA GitHub']);
    GitAccount::create([
        'provider' => 'bitbucket',
        'label' => 'BBB Bitbucket',
        'identifier' => 'acme',
        'workspace' => 'acme',
        'token' => 'atl_token',
        'last_verified_at' => now(),
    ]);

    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octocat'], 200),
        'api.bitbucket.org/*' => Http::response(['error' => 'revoked'], 401),
    ]);

    $response = $this->withHeaders(asAdmin())->getJson('/api/integrations/git/accounts/status');

    $response->assertOk()->assertJsonCount(2, 'statuses');
    $response->assertJsonPath('statuses.0.status', 'valid');
    $response->assertJsonPath('statuses.1.status', 'invalid');
    // Bitbucket never exposes an expiry — null means "none", not "lookup failed".
    $response->assertJsonPath('statuses.1.expires_at', null);
});

it('reads the gitlab token expiry from the self endpoint', function () {
    GitAccount::create([
        'provider' => 'gitlab',
        'label' => 'GitLab',
        'identifier' => 'dev',
        'token' => 'glpat_x',
        'last_verified_at' => now(),
    ]);

    Http::fake([
        'gitlab.com/api/v4/personal_access_tokens/self' => Http::response([
            'active' => true, 'revoked' => false, 'expires_at' => '2026-09-01',
        ], 200),
    ]);

    $response = $this->withHeaders(asAdmin())->getJson('/api/integrations/git/accounts/status');

    $response->assertOk();
    $response->assertJsonPath('statuses.0.status', 'valid');
    $response->assertJsonPath('statuses.0.expires_at', '01-09-2026 00:00:00');
});

it('treats a revoked gitlab token as invalid even when the api describes it', function () {
    GitAccount::create([
        'provider' => 'gitlab',
        'label' => 'GitLab',
        'identifier' => 'dev',
        'token' => 'glpat_x',
        'last_verified_at' => now(),
    ]);

    Http::fake([
        'gitlab.com/api/v4/personal_access_tokens/self' => Http::response([
            'active' => false, 'revoked' => true, 'expires_at' => '2026-09-01',
        ], 200),
    ]);

    $this->withHeaders(asAdmin())->getJson('/api/integrations/git/accounts/status')
        ->assertOk()
        ->assertJsonPath('statuses.0.status', 'invalid');
});

it('falls back to the user endpoint when a gitlab token lacks read_api scope', function () {
    GitAccount::create([
        'provider' => 'gitlab',
        'label' => 'GitLab',
        'identifier' => 'dev',
        'token' => 'glpat_repo_only',
        'last_verified_at' => now(),
    ]);

    Http::fake([
        // read_repository-only tokens are refused here — that says nothing
        // about the token's health.
        'gitlab.com/api/v4/personal_access_tokens/self' => Http::response(['message' => '403 Forbidden'], 403),
        'gitlab.com/api/v4/user' => Http::response(['username' => 'dev'], 200),
    ]);

    $response = $this->withHeaders(asAdmin())->getJson('/api/integrations/git/accounts/status');

    $response->assertOk();
    $response->assertJsonPath('statuses.0.status', 'valid');
    $response->assertJsonPath('statuses.0.expires_at', null);
});

it('denies the status endpoint without the git permission', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/integrations/git/accounts/status')
        ->assertForbidden();
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
