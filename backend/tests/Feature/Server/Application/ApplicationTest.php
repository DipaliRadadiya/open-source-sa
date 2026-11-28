<?php

use App\Actions\Server\Application\CreateApplication;
use App\Models\Application;
use App\Models\AppPackageRelease;
use App\Models\GitAccount;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    // Provisioning is dispatched on create and has its own test file; fake the queue here.
    Queue::fake();
});

/** A server that can run everything, so tests aren't at the mercy of the host. */
function capableServer(bool $php = true, bool $node = true): ServerCapability
{
    // The catalog refuses a type whose database engine does not answer, so a capable server
    // needs one too.
    fakeUsableSqlEngine();

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

it('sends the catalog as a JSON array, not an object keyed by position', function () {
    // The other tests read the payload via `collect(...)->keyBy('name')`, which treats a JSON
    // array and object alike, so they would pass a catalog like `{"20": {...}}`. That happens
    // when `array_filter` keeps keys after the Docker types are dropped; this server filters
    // something, so the case can fail.
    capableServer();

    $response = $this->withHeaders(appHeaders())->getJson('/api/site-types');

    $response->assertOk();

    // The raw body is what the frontend's `z.array()` parses; the decoded form alone could hide an
    // encoding change.
    expect(array_is_list($response->json('site_types')))->toBeTrue()
        ->and($response->getContent())->toContain('"site_types":[');
});

it('greys out types this server cannot run, with a reason and what to install', function () {
    capableServer(php: false, node: true);

    $response = $this->withHeaders(appHeaders())->getJson('/api/site-types');

    $types = collect($response->json('site_types'))->keyBy('name');

    // PHP is missing, so WordPress is offered but not usable...
    expect($types['wordpress']['available'])->toBeFalse();
    expect($types['wordpress']['unavailable_reason'])->toBe('This server does not have PHP installed.');
    expect($types['wordpress']['installable_runtime'])->toBe('php');
    // The code, not the sentence: the sentence is translated and may be reworded.
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
    // The response returns before provisioning runs, so the app must not look live yet.
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

describe('a git repository that is not there', function () {
    // A wrong URL must create nothing, instead of a site that says "Running" and fails to deploy.
    function createFromPublicUrl(string $branch = 'main'): TestResponse
    {
        return test()->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'git',
            'name' => 'Typo',
            'domain' => 'typo.example.com',
            'system_user_id' => test()->su->id,
            'git_source' => 'public_url',
            'repository_url' => 'https://github.com/laravel/laravl.git',
            'branch' => $branch,
            'rendering_type' => 'php',
        ]);
    }

    it('refuses a repository that cannot be reached', function () {
        capableServer();
        Process::fake(fn ($process) => fakeDatabaseAnswer($process) ?? (in_array('ls-remote', $process->command, true)
            ? Process::result(errorOutput: "remote: Repository not found.\nfatal: repository 'https://github.com/laravel/laravl.git/' not found", exitCode: 128)
            : Process::result(exitCode: 1)));

        createFromPublicUrl()->assertUnprocessable()
            ->assertJsonPath('errors.repository_url.0', __('validation.git_repository_unreachable'));

        expect(Application::where('domain', 'typo.example.com')->exists())->toBeFalse();
    });

    it('refuses a branch the repository does not have', function () {
        capableServer();
        Process::fake(fn ($process) => fakeDatabaseAnswer($process) ?? (in_array('ls-remote', $process->command, true)
            ? Process::result(output: '')
            : Process::result(exitCode: 1)));

        createFromPublicUrl('mian')->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', __('validation.git_branch_missing', ['branch' => 'mian']));
    });

    it('says when the git host itself cannot be reached', function () {
        capableServer();
        Process::fake(fn ($process) => fakeDatabaseAnswer($process) ?? (in_array('ls-remote', $process->command, true)
            ? Process::result(errorOutput: "fatal: unable to access 'https://github.com/x/y.git/': Could not resolve host: github.com", exitCode: 128)
            : Process::result(exitCode: 1)));

        createFromPublicUrl()->assertUnprocessable()
            ->assertJsonPath('errors.repository_url.0', __('validation.git_host_unreachable'));
    });
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

    // A partial settings update must not wipe what it didn't mention; the name is immutable. See
    // {@see \App\Http\Requests\Server\Application\UpdateApplicationRequest}.
    $this->withHeaders(appHeaders())->putJson("/api/applications/{$app->id}", [
        'settings' => ['b' => 2],
    ])->assertOk()->assertJsonPath('application.name', 'Site');

    expect($app->fresh()->settings)->toBe(['a' => 1, 'b' => 2]);

    $this->withHeaders(appHeaders())->deleteJson("/api/applications/{$app->id}")->assertOk();
    expect(Application::count())->toBe(0);
});

it('refuses a domain on the general update, which changed the column and nothing else', function () {
    // Only the Domains endpoints move the rows, vhost and certificate, check the name is free and
    // require `app_domain` manage.
    $app = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Site',
        'slug' => 'site', 'domain' => 'site.example.com',
        'site_type' => 'static', 'serving_profile' => 'static', 'status' => 'pending',
    ]);

    $this->withHeaders(appHeaders())->putJson("/api/applications/{$app->id}", [
        'domain' => 'other.example.com',
    ])->assertStatus(422)
        ->assertJsonPath('errors.domain.0', __('validation.application_domain_use_endpoint'));

    expect($app->fresh()->domain)->toBe('site.example.com');

    // What the UI really sends here still works.
    $this->withHeaders(appHeaders())->putJson("/api/applications/{$app->id}", [
        'settings' => ['b' => 2],
    ])->assertOk();
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

    // The grid must tell "click and get WordPress" apart from "click and get an empty directory".
    expect($types['wordpress']['has_installer'])->toBeTrue()
        ->and($types['git']['has_installer'])->toBeFalse()
        ->and($types['php']['has_installer'])->toBeFalse()
        ->and($types['static']['has_installer'])->toBeFalse();

    // `installable_runtime` is a different question: what to install to enable an unavailable card.
    expect($types['wordpress']['installable_runtime'])->toBeNull();
});

describe('node version constraints', function () {
    /**
     * An application that refuses an unsupported Node version (n8n exits) would be reported as
     * created while its reverse proxy points at a dead port, so the version must be validated.
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

    it('refuses a Node above the ceiling when the release declares one', function () {
        capableServer();

        /*
         * Too new is as fatal as too old for an application that refuses to start outside its
         * range. The range comes from a stored release (n8n 1.x publishes `>=20.19 <= 24.x`),
         * not a literal in the site type.
         */
        AppPackageRelease::create([
            'package' => 'n8n',
            'version' => '1.123.81',
            'node_range' => '>=20.19 <= 24.x',
        ]);

        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'admin_email' => 'owner@example.com',
            'admin_password' => 'Flows-Pass-2026',
            'node_version' => '25',
        ])->assertStatus(422)->assertJsonValidationErrors('node_version');
    });

    it('allows a Node the current release does not exclude', function () {
        capableServer();
        config(['server.installers.n8n.node_max' => null]);

        // n8n 2.x declares no upper bound, so a hand-written `max` would refuse a Node it accepts.
        AppPackageRelease::create([
            'package' => 'n8n',
            'version' => '2.39.7',
            'node_range' => '>=24.0.0',
        ]);

        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'admin_email' => 'owner@example.com',
            'admin_password' => 'Flows-Pass-2026',
            'node_version' => '25',
        ])->assertCreated();
    });

    it('refuses n8n on a Node newer than its configured ceiling', function () {
        capableServer();

        // Allowed by n8n itself, but its Kafka client would compile from source past the timeout.
        AppPackageRelease::create([
            'package' => 'n8n',
            'version' => '2.49.8',
            'node_range' => '>=24.0.0',
        ]);

        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'admin_email' => 'owner@example.com',
            'admin_password' => 'Flows-Pass-2026',
            'node_version' => '26',
        ])->assertUnprocessable()->assertJsonValidationErrors('node_version');
    });

    it('refuses a Node its installed n8n cannot run on, even though 1.x could', function () {
        capableServer();

        /*
         * The floor follows the installed version: n8n 2.x declares `engines: {node: ">=24.0.0"}`
         * while 1.x ran from 20.19, so Node 22 must be refused.
         */
        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'admin_email' => 'owner@example.com',
            'admin_password' => 'Flows-Pass-2026',
            'node_version' => '22',
        ])->assertStatus(422)->assertJsonValidationErrors('node_version');
    });

    it('accepts a version inside the range, including the top of the ceiling series', function () {
        capableServer();

        // 24.7 must pass against a ceiling written as `24`: the ceiling is a major series.
        $this->withHeaders(appHeaders())->postJson('/api/applications', [
            'site_type' => 'n8n',
            'name' => 'Flows',
            'domain' => 'flows.example.com',
            'system_user_id' => test()->su->id,
            'admin_email' => 'owner@example.com',
            'admin_password' => 'Flows-Pass-2026',
            'node_version' => '24.7.0',
        ])->assertCreated();
    });

    it('leaves types with no declared range alone', function () {
        capableServer();
        test()->account = GitAccount::create([
            'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat', 'token' => 'ghp_x',
        ]);

        // A git site runs code the panel knows nothing about, so it has no version opinion.
        $this->withHeaders(appHeaders())
            ->postJson('/api/applications', gitPayload(['node_version' => '18']))
            ->assertCreated();
    });

    it('publishes the range in the catalog so the picker can filter', function () {
        capableServer();

        $response = $this->withHeaders(appHeaders())->getJson('/api/site-types')->assertOk();

        $types = collect($response->json('site_types'))->keyBy('name');

        // No release is stored here, so the site type's fallback answers (n8n: floor 24, capped at
        // the configured ceiling).
        expect($types['nodebb']['node_version_range'])->toBe(['min' => '22', 'max' => null])
            ->and($types['n8n']['node_version_range'])->toBe(['min' => '24', 'max' => '24'])
            ->and($types['wordpress']['node_version_range'])->toBeNull();
    });
});

it('refuses an application name that would be two systemd directives', function () {
    // The name is rendered into the unit's `Description=`; a newline there injects a directive.
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

it('refuses a hostname with an empty or hyphen-edged label', function (string $domain) {
    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'static',
        'name' => 'Bad host',
        'domain' => $domain,
        'system_user_id' => $this->su->id,
    ])->assertStatus(422)
        ->assertJsonPath('errors.domain.0', __('errors/application.invalid_domain'));

    expect(Application::count())->toBe(0);
})->with(['-bad-.example.com', 'qa..example.com']);

it('accepts a punycode top-level domain', function () {
    $response = $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'static',
        'name' => 'Punycode',
        'domain' => 'example.xn--p1ai',
        'system_user_id' => $this->su->id,
    ]);

    expect($response->json('errors.domain'))->toBeNull();
});

it('answers an unknown site type with a database engine as a 422, not a 500', function () {
    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'not-a-type',
        'name' => 'Unknown',
        'domain' => 'unknown.example.com',
        'system_user_id' => $this->su->id,
        'database_engine' => 'mysql',
    ])->assertStatus(422)->assertJsonValidationErrors('site_type');
});

it('refuses a Joomla table prefix that does not start with a letter', function () {
    // Joomla's own installer refuses it, after the site has been created.
    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'joomla',
        'name' => 'Joomla',
        'domain' => 'joomla.example.com',
        'system_user_id' => $this->su->id,
        'table_prefix' => '1abc_',
    ])->assertStatus(422)->assertJsonValidationErrors('table_prefix');
});

it('names the password rule an admin password misses', function () {
    capableServer();

    // n8n: lowercase, uppercase, number — no symbol rule.
    $this->withHeaders(appHeaders())->postJson('/api/applications', [
        'site_type' => 'n8n',
        'name' => 'Flows',
        'domain' => 'flows.example.com',
        'system_user_id' => test()->su->id,
        'admin_email' => 'owner@example.com',
        'admin_password' => 'flows-pass-2026',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['admin_password' => __('validation.admin_password_strength_no_symbol')]);
});
