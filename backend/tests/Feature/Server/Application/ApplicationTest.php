<?php

use App\Actions\Server\Application\CreateApplication;
use App\Models\Application;
use App\Models\GitAccount;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    // These cover the record and the catalog. Provisioning is dispatched on
    // create and has its own test file; faking the queue keeps the two
    // concerns from bleeding into each other.
    Queue::fake();
});

/** A server that can run everything, so tests aren't at the mercy of the host. */
function capableServer(bool $php = true, bool $node = true): ServerCapability
{
    return ServerCapability::create([
        'stack' => $php && ! $node ? 'lemp' : 'mern',
        'web_server' => 'nginx',
        'capabilities' => ['php' => $php, 'node' => $node],
        'source' => 'installer',
        'verified_at' => now(),
    ]);
}

function appHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/** A valid git-from-account payload. */
function gitPayload(array $overrides = []): array
{
    return array_merge([
        'site_type' => 'git',
        'name' => 'My shop',
        'domain' => 'shop.example.com',
        'system_user_id' => test()->su->id,
        'git_source' => 'account',
        'git_account_id' => test()->account->id,
        'repository' => 'octocat/hello',
        'branch' => 'main',
        // How the repository is served has no safe default, so the card asks.
        'rendering_type' => 'php',
    ], $overrides);
}

it('returns the site type catalog with field schemas', function () {
    capableServer();

    $response = $this->withHeaders(appHeaders())->getJson('/api/site-types');

    $response->assertOk();
    $types = collect($response->json('site_types'))->keyBy('name');

    expect($types->keys()->all())->toContain('wordpress', 'git', 'php', 'static');

    $git = $types['git'];
    expect($git['title'])->toBe('From Git repo');
    expect($git['available'])->toBeTrue();
    expect($git['method'])->toBe('git');
    expect($git['serving_profile'])->toBe('php');

    // The two keys that let the frontend write one generic form renderer.
    $fields = collect($git['fields'])->keyBy('name');
    expect($fields['git_account_id']['source'])->toBe('git_accounts');
    expect($fields['repository']['depends_on'])->toBe('git_account_id');
    expect($fields['branch']['depends_on'])->toBe('repository');

    // WordPress is the type with the most fields — if it renders, all do.
    expect(collect($types['wordpress']['fields'])->pluck('name')->all())
        ->toContain('site_title', 'admin_email', 'admin_password', 'table_prefix');
    expect($types['wordpress']['needs_database'])->toBeTrue();
});

it('greys out types this server cannot run, with a reason and what to install', function () {
    capableServer(php: false, node: true);

    $response = $this->withHeaders(appHeaders())->getJson('/api/site-types');

    $types = collect($response->json('site_types'))->keyBy('name');

    // PHP is missing, so WordPress is offered but not usable...
    expect($types['wordpress']['available'])->toBeFalse();
    expect($types['wordpress']['unavailable_reason'])->toBe('This server does not have PHP installed.');
    expect($types['wordpress']['installable_runtime'])->toBe('php');
    // The code, not the sentence. The sentence is translated and will be
    // reworded; anything branching on it breaks the day somebody improves it.
    expect($types['wordpress']['unavailable_code'])->toBe('runtime');

    // ...and a type needing no runtime is always available.
    expect($types['static']['available'])->toBeTrue();
    expect($types['static']['unavailable_reason'])->toBeNull();
    expect($types['static']['unavailable_code'])->toBeNull();
});

it('detects and stores the server record when the installer never ran', function () {
    expect(ServerCapability::count())->toBe(0);

    // No node binary on this "server"; php_dir points nowhere.
    Process::fake(fn () => Process::result(exitCode: 1));
    config(['server.php_dir' => sys_get_temp_dir().'/no-php-here', 'server.web_servers' => []]);

    $this->withHeaders(appHeaders())->getJson('/api/site-types')->assertOk();

    $record = ServerCapability::first();
    expect($record)->not->toBeNull();
    expect($record->source)->toBe('detected');
    expect($record->stack)->toBeNull();          // we did not build this box
    expect($record->can('php'))->toBeFalse();
    expect($record->can('node'))->toBeFalse();
});

it('creates a git application from a connected account, left undeployed', function () {
    capableServer();
    $this->account = GitAccount::create([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat', 'token' => 'ghp_x',
    ]);

    $response = $this->withHeaders(appHeaders())->postJson('/api/applications', gitPayload());

    $response->assertCreated();
    $response->assertJsonPath('application.site_type', 'git');
    $response->assertJsonPath('application.repository', 'octocat/hello');
    $response->assertJsonPath('application.branch', 'main');
    // The response is returned before provisioning runs, and until it
    // succeeds the app must never look live.
    $response->assertJsonPath('application.status', 'pending');
    $response->assertJsonPath('application.status_title', 'Not deployed yet');
    $response->assertJsonPath('application.deployed', false);

    // serving_profile comes from the site type, never from the client.
    expect(Application::first()->serving_profile)->toBe('php');
});

it('creates a git application from a public url with no account', function () {
    capableServer();

    $response = $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'git',
        'name' => 'Laravel',
        'domain' => 'demo.example.com',
        'system_user_id' => $this->su->id,
        'git_source' => 'public_url',
        'repository_url' => 'https://github.com/laravel/laravel.git',
        'branch' => 'main',
        'rendering_type' => 'php',
    ]);

    $response->assertCreated();
    // No credential is involved at all for a public repo.
    $response->assertJsonPath('application.git_account_id', null);
    $response->assertJsonPath('application.repository_url', 'https://github.com/laravel/laravel.git');
});

it('rejects a public repository url pointing at the server itself', function () {
    capableServer();

    foreach (['http://github.com/a/b.git', 'https://127.0.0.1/a/b.git', 'https://169.254.169.254/a/b.git'] as $url) {
        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'git',
            'name' => 'Bad',
            'domain' => 'bad.example.com',
            'system_user_id' => $this->su->id,
            'git_source' => 'public_url',
            'repository_url' => $url,
            'branch' => 'main',
        ])->assertStatus(422)->assertJsonValidationErrors('repository_url');
    }

    expect(Application::count())->toBe(0);
});

it('requires the fields the chosen site type declares', function () {
    capableServer();

    // WordPress without its admin email / title / password.
    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'wordpress',
        'name' => 'Blog',
        'domain' => 'blog.example.com',
        'system_user_id' => $this->su->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['site_title', 'admin_email', 'admin_password']);

    expect(Application::count())->toBe(0);
});

it('rejects a primary domain already owned by another application', function () {
    capableServer();

    $existing = Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Existing site',
        'slug' => 'existing-site',
        'domain' => 'taken.example.com',
        'site_type' => 'static',
        'serving_profile' => 'static',
        'status' => 'pending',
    ]);
    $existing->domains()->create([
        'domain' => 'taken.example.com',
        'type' => 'primary',
    ]);

    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'static',
        'name' => 'New site',
        'domain' => ' TAKEN.EXAMPLE.COM ',
        'system_user_id' => $this->su->id,
    ])->assertStatus(422)->assertJsonValidationErrors('domain');

    expect(Application::count())->toBe(1);
});

it('rolls back the application when its primary domain loses a uniqueness race', function () {
    capableServer();

    $existing = Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Existing site',
        'slug' => 'existing-site',
        'domain' => 'taken.example.com',
        'site_type' => 'static',
        'serving_profile' => 'static',
        'status' => 'pending',
    ]);
    $existing->domains()->create([
        'domain' => 'taken.example.com',
        'type' => 'primary',
    ]);

    expect(fn () => app(CreateApplication::class)->execute([
        'site_type' => 'static',
        'name' => 'New site',
        'domain' => 'taken.example.com',
        'system_user_id' => $this->su->id,
    ]))->toThrow(ValidationException::class);

    expect(Application::pluck('name')->all())->toBe(['Existing site']);
});

it('can recreate an application with the same name and domain after deletion', function () {
    capableServer();

    $payload = [
        'site_type' => 'static',
        'name' => 'Reusable site',
        'domain' => 'reusable.example.com',
        'system_user_id' => $this->su->id,
    ];

    $created = $this->withHeaders(appHeaders())
        ->postJson('/api/applications', $payload)
        ->assertCreated();

    $this->withHeaders(appHeaders())
        ->deleteJson('/api/applications/'.$created->json('application.id'))
        ->assertOk();

    expect(Application::count())->toBe(0);

    $this->withHeaders(appHeaders())
        ->postJson('/api/applications', $payload)
        ->assertCreated();

    expect(Application::count())->toBe(1)
        ->and(Application::first()->domains()->where('domain', 'reusable.example.com')->exists())->toBeTrue();
});

it('stores the type-specific answers and ignores anything not in the schema', function () {
    capableServer();

    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'wordpress',
        'name' => 'Blog',
        'domain' => 'blog.example.com',
        'system_user_id' => $this->su->id,
        'site_title' => 'My Blog',
        'admin_user' => 'admin',
        'admin_email' => 'me@example.com',
        'admin_password' => 'Str0ngPassw0rd',
        'table_prefix' => 'wp_',
        // Not declared by the site type — must not be stored.
        'is_admin' => true,
    ])->assertCreated();

    $settings = Application::first()->settings;

    expect($settings['site_title'])->toBe('My Blog');
    expect($settings['table_prefix'])->toBe('wp_');
    expect($settings)->not->toHaveKey('is_admin');
});

it('refuses a site type the server cannot run', function () {
    capableServer(php: false, node: true);

    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'php',
        'name' => 'Nope',
        'domain' => 'nope.example.com',
        'system_user_id' => $this->su->id,
    ])->assertStatus(422)->assertJsonValidationErrors('site_type');

    expect(Application::count())->toBe(0);
});

it('rejects an unknown site type', function () {
    capableServer();

    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'drupal',
        'name' => 'X',
        'domain' => 'x.example.com',
        'system_user_id' => $this->su->id,
    ])->assertStatus(422)->assertJsonValidationErrors('site_type');
});

it('lists, shows, updates and deletes an application', function () {
    capableServer();
    $app = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Site',
        'slug' => 'site', 'domain' => 'site.example.com',
        'site_type' => 'static', 'serving_profile' => 'static', 'status' => 'pending',
        'settings' => ['a' => 1],
    ]);

    $this->withHeaders(appHeaders())->getJson('/api/applications')
        ->assertOk()->assertJsonCount(1, 'applications')
        ->assertJsonPath('applications.0.system_user.username', 'deploy');

    $this->withHeaders(appHeaders())->getJson("/api/applications/{$app->id}")
        ->assertOk()->assertJsonPath('application.name', 'Site');

    // A partial settings update must not wipe what it didn't mention.
    $this->withHeaders(appHeaders())->putJson("/api/applications/{$app->id}", [
        'name' => 'Renamed', 'settings' => ['b' => 2],
    ])->assertOk()->assertJsonPath('application.name', 'Renamed');

    expect($app->fresh()->settings)->toBe(['a' => 1, 'b' => 2]);

    $this->withHeaders(appHeaders())->deleteJson("/api/applications/{$app->id}")->assertOk();
    expect(Application::count())->toBe(0);
});

it('records the creation in the activity log', function () {
    capableServer();

    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'static', 'name' => 'Site', 'domain' => 'site.example.com',
        'system_user_id' => $this->su->id,
    ])->assertCreated();

    $this->withHeaders(appHeaders())->getJson('/api/activity-log')
        ->assertOk()
        ->assertJsonPath('activity_log.0.type', 'application')
        ->assertJsonPath('activity_log.0.action', 'created');
});

it('reports what the server is and can run', function () {
    capableServer(php: true, node: false);

    $response = $this->withHeaders(appHeaders())->getJson('/api/server/capabilities');

    $response->assertOk();
    $response->assertJsonPath('capabilities.stack', 'lemp');
    $response->assertJsonPath('capabilities.web_server', 'nginx');
    $response->assertJsonPath('capabilities.capabilities.php', true);
    $response->assertJsonPath('capabilities.capabilities.node', false);
    $response->assertJsonPath('capabilities.source', 'installer');
});

it('denies a user without the application permission', function () {
    capableServer();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/site-types')->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/applications')->assertForbidden();
});

it('denies creating and deleting with view-only access', function () {
    capableServer();
    $user = User::factory()->create();
    grantPermission($user, 'application', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $app = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Site',
        'slug' => 'site', 'domain' => 'site.example.com',
        'site_type' => 'static', 'serving_profile' => 'static', 'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/applications')->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/applications', [
            'site_type' => 'static', 'name' => 'X', 'domain' => 'x.example.com',
            'system_user_id' => $this->su->id,
        ])->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/applications/{$app->id}")->assertForbidden();

    expect(Application::count())->toBe(1);
});

it('says which types actually install something', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/site-types');

    $types = collect($response->json('site_types'))->keyBy('name');

    // The grid has to tell "click and get WordPress" apart from "click and get
    // an empty directory" — they are the same card otherwise.
    expect($types['wordpress']['has_installer'])->toBeTrue()
        ->and($types['git']['has_installer'])->toBeFalse()
        ->and($types['php']['has_installer'])->toBeFalse()
        ->and($types['static']['has_installer'])->toBeFalse();

    // And `installable_runtime` answers a different question: what to install
    // to make an unavailable card work. It is not about the app at all.
    expect($types['wordpress']['installable_runtime'])->toBeNull();
});

describe('node version constraints', function () {
    /**
     * The version picker offered every installed Node to every Node site type,
     * because it is one shared `nodeFields()` select and nothing narrowed it.
     * A version the application refuses to run on produced a site the panel
     * reported as created and a domain that served nothing: n8n exits on an
     * unsupported version, so the reverse proxy points at a dead port.
     */
    it('refuses a Node version below what the application supports', function () {
        capableServer();

        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'nodebb',
            'name' => 'Forum',
            'domain' => 'forum.example.com',
            'system_user_id' => test()->su->id,
            // NodeBB v4.x requires 22 or greater.
            'node_version' => '20',
            'admin_username' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'sup3rs3cret!',
        ])->assertStatus(422)->assertJsonValidationErrors('node_version');
    });

    it('refuses a Node version above the ceiling, which n8n has and NodeBB does not', function () {
        capableServer();

        // The ceiling is the half that is easy to forget: too *new* is just as
        // fatal for n8n, which supports 20.19 through 24.x and refuses the rest.
        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'node_version' => '25',
        ])->assertStatus(422)->assertJsonValidationErrors('node_version');
    });

    it('accepts a version inside the range, including the top of the ceiling series', function () {
        capableServer();

        // 24.7 must pass against a ceiling written as `24`: the ceiling is a
        // major series, not a point release, or it goes stale every patch.
        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'node_version' => '24.7.0',
        ])->assertCreated();
    });

    it('leaves types with no declared range alone', function () {
        capableServer();
        test()->account = GitAccount::create([
            'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat', 'token' => 'ghp_x',
        ]);

        // A git site runs code the panel knows nothing about, so it has no
        // business having an opinion about the version.
        $this->withHeaders(appHeaders())
            ->postJson('/api/applications', gitPayload(['node_version' => '18']))
            ->assertCreated();
    });

    it('publishes the range in the catalog so the picker can filter', function () {
        capableServer();

        $response = $this->withHeaders(appHeaders())->getJson('/api/site-types')->assertOk();

        $types = collect($response->json('site_types'))->keyBy('name');

        expect($types['nodebb']['node_version_range'])->toBe(['min' => '22', 'max' => null])
            ->and($types['n8n']['node_version_range'])->toBe(['min' => '20.19', 'max' => '24'])
            ->and($types['wordpress']['node_version_range'])->toBeNull();
    });
});

it('refuses an application name that would be two systemd directives', function () {
    // A Node application's name is rendered into its unit's `Description=`,
    // in a file the panel writes and systemd executes. A newline there is a
    // directive of the caller's choosing.
    capableServer();
    $this->account = GitAccount::create([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat', 'token' => 'ghp_x',
    ]);

    $this->withHeaders(appHeaders())
        ->postJson('/api/applications', gitPayload([
            'name' => "My shop\nExecStartPre=/bin/sh -c 'id > /tmp/pwned'",
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});
