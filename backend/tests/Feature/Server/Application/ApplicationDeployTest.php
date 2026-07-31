<?php

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\GitAccount;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->account = GitAccount::create([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat',
        'token' => 'ghp_super_secret_value',
    ]);
});

function deployHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function gitApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
        'git_account_id' => test()->account->id,
        'repository' => 'octocat/hello',
        'branch' => 'main',
    ], $overrides));
}

function runDeploy(Application $application): void
{
    (new DeployApplication($application->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );
}

/** Fake git, reporting a commit for rev-parse and "not a repo" for the detect. */
function fakeGit(): void
{
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'test' => Process::result(exitCode: 1),
        in_array('rev-parse', $process->command, true) => Process::result(output: "abc123def456\n"),
        default => Process::result(exitCode: 0),
    });
}

it('clones a private repository without the token ever touching the command line', function () {
    fakeGit();
    $app = gitApp();

    runDeploy($app);

    // This is the point of the whole design: the secret is never an argument.
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), 'ghp_super_secret_value'));

    // It reaches git through a credential file written over stdin instead.
    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        && str_contains((string) $p->input, 'ghp_super_secret_value')
        && str_contains((string) $p->input, 'x-access-token'));

    Process::assertRan(fn ($p) => $p->command[0] === 'chmod' && $p->command[1] === '0600');

    // The remote itself is clean — nothing sensitive lands in .git/config.
    Process::assertRan(fn ($p) => in_array('clone', $p->command, true)
        && in_array('https://github.com/octocat/hello.git', $p->command, true));

    expect($app->fresh()->last_commit)->toBe('abc123def456');
    expect($app->fresh()->status->value)->toBe('active');
});

it('deletes the credential file even when the clone fails', function () {
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'test' => Process::result(exitCode: 1),
        in_array('clone', $process->command, true) => Process::result(errorOutput: 'fatal: auth failed', exitCode: 128),
        default => Process::result(exitCode: 0),
    });

    $app = gitApp();
    runDeploy($app);

    // A failed deploy must not leave a token sitting on disk.
    Process::assertRan(fn ($p) => $p->command[0] === 'rm'
        && $p->command[1] === '-f'
        && str_contains((string) $p->command[2], 'git-'));
});

it('keeps a live site live when a redeploy fails', function () {
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'test' => Process::result(exitCode: 0), // already cloned
        in_array('fetch', $process->command, true) => Process::result(errorOutput: 'network down', exitCode: 1),
        default => Process::result(exitCode: 0),
    });

    $app = gitApp(['status' => 'active']);
    runDeploy($app);

    $app->refresh();
    // The old code is still on disk and still being served — reporting the
    // site as failed would be a lie.
    expect($app->status->value)->toBe('active');
    expect($app->failed_step)->toBe('fetch');
    expect($app->reference)->not->toBeEmpty();
});

it('fetches and hard-resets an already-cloned repository', function () {
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'test' => Process::result(exitCode: 0),
        in_array('rev-parse', $process->command, true) => Process::result(output: "newsha\n"),
        default => Process::result(exitCode: 0),
    });

    $app = gitApp();
    runDeploy($app);

    Process::assertRan(fn ($p) => in_array('fetch', $p->command, true));
    Process::assertRan(fn ($p) => in_array('reset', $p->command, true) && in_array('--hard', $p->command, true));
    Process::assertNotRan(fn ($p) => in_array('clone', $p->command, true));

    expect($app->fresh()->steps)->toContain('fetch', 'checkout');
});

it('needs no credential at all for a public repository', function () {
    fakeGit();
    $app = gitApp([
        'git_account_id' => null,
        'repository' => null,
        'repository_url' => 'https://github.com/laravel/laravel.git',
    ]);

    runDeploy($app);

    // Nothing to write, nothing to leak, nothing to clean up.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
    Process::assertRan(fn ($p) => in_array('https://github.com/laravel/laravel.git', $p->command, true));
});

it('runs the build command as the site user, not as the panel', function () {
    fakeGit();
    $app = gitApp(['build_command' => 'composer install --no-dev']);

    runDeploy($app);

    // The build command is whatever the user typed. Dropping privileges is
    // what stops `application:manage` from becoming root-level RCE.
    Process::assertRan(fn ($p) => $p->command[0] === 'runuser'
        && $p->command[2] === 'deploy'
        && str_contains(end($p->command), 'composer install --no-dev'));

    expect($app->fresh()->steps)->toContain('build');
});

it('builds with the Node version the site pinned, not the default', function () {
    fakeGit();
    $app = gitApp(['build_command' => 'npm ci && npm run build', 'node_version' => '18.20.0']);

    runDeploy($app);

    // A site pinned to 18 on a box defaulting to 22 built with 22 — silently,
    // and only visible much later as a runtime error in code that compiled.
    Process::assertRan(fn ($p) => $p->command[0] === 'runuser'
        && str_contains(end($p->command), "export PATH='/opt/fnm/node-versions/v18.20.0/installation/bin':\"\$PATH\";")
        && str_contains(end($p->command), 'npm ci && npm run build'));
});

it('leaves PATH alone when the site pinned no version', function () {
    fakeGit();
    $app = gitApp(['build_command' => 'composer install', 'node_version' => null]);

    runDeploy($app);

    // A PHP site has no business having its PATH rewritten.
    Process::assertRan(fn ($p) => $p->command[0] === 'runuser'
        && ! str_contains(end($p->command), 'export PATH='));
});

it('pairs the token with the right username per provider', function () {
    fakeGit();
    $this->account->update(['provider' => 'gitlab', 'identifier' => 'dev']);
    $app = gitApp();

    runDeploy($app);

    // The wrong username fails auth even with a valid token.
    Process::assertRan(fn ($p) => $p->command[0] === 'tee' && str_contains((string) $p->input, 'oauth2'));
});

it('queues a deploy and refuses one for a non-git application', function () {
    Queue::fake();
    $app = gitApp();

    $this->withHeaders(deployHeaders())
        ->postJson("/api/applications/{$app->id}/deploy")
        ->assertStatus(202);

    Queue::assertPushed(DeployApplication::class);

    $static = Application::create([
        'system_user_id' => $this->su->id, 'name' => 'S', 'domain' => 's.example.com',
        'site_type' => 'static', 'serving_profile' => 'static', 'status' => 'active',
    ]);

    $this->withHeaders(deployHeaders())
        ->postJson("/api/applications/{$static->id}/deploy")
        ->assertStatus(422);
});

it('never returns the git token or the raw git error', function () {
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'test' => Process::result(exitCode: 1),
        in_array('clone', $process->command, true) => Process::result(
            errorOutput: 'fatal: could not read Password for https://x-access-token:ghp_super_secret_value@github.com',
            exitCode: 128,
        ),
        default => Process::result(exitCode: 0),
    });

    $app = gitApp();
    runDeploy($app);

    $body = json_encode($this->withHeaders(deployHeaders())->getJson("/api/applications/{$app->id}")->json());

    expect($body)->not->toContain('ghp_super_secret_value');
    expect($body)->not->toContain('could not read Password');
});

it('denies deploying with view-only access', function () {
    Queue::fake();
    $user = User::factory()->create();
    grantPermission($user, 'application', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;
    $app = gitApp();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/applications/{$app->id}/deploy")
        ->assertForbidden();

    Queue::assertNothingPushed();
});
