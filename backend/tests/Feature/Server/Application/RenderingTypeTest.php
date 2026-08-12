<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/**
 * How a git application is built decides how it is served.
 *
 * Static and client-side rendering produce files a web server hands out
 * directly. Server-side rendering produces a process to proxy to. Getting the
 * two confused is invisible until the site is live: a directory served for an
 * app that routes in code publishes its source, and a proxy with nothing
 * behind it is a permanent 502.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'mern', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'gituser', 'home_path' => '/home/gituser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    Process::fake(fn () => Process::result(output: ''));
});

function renderPayload(array $overrides = []): array
{
    return array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'App', 'domain' => 'app.test', 'site_type' => 'git',
        'git_source' => 'public_url',
        'repository_url' => 'https://github.com/laravel/laravel.git',
        'branch' => 'main',
        'rendering_type' => 'ssr',
        'start_command' => 'node server.js',
        'package_manager' => 'npm',
    ], $overrides);
}

function createRendered(array $overrides = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/applications', renderPayload($overrides));
}

it('serves server-side rendering by proxy', function () {
    createRendered()
        ->assertCreated()
        ->assertJsonPath('application.serving_profile', 'node')
        ->assertJsonPath('application.rendering_type', 'ssr')
        // A process needs somewhere to listen, and the panel picks one.
        ->assertJsonPath('application.has_process', true);
});

it('serves the built files for client-side rendering, with no process', function (string $type) {
    $response = createRendered([
        'rendering_type' => $type,
        'domain' => "{$type}.test",
        'start_command' => null,
    ])->assertCreated();

    // No process, so no unit, no port, and nothing to proxy to.
    expect($response->json('application.serving_profile'))->toBe('static')
        ->and($response->json('application.has_process'))->toBeFalse()
        ->and($response->json('application.app_port'))->toBeNull();
})->with(['csr', 'static']);

it('requires something to start for server-side rendering', function () {
    // Otherwise the app is served by proxy with nothing behind it — a vhost
    // that returns 502 forever and gives no hint why.
    createRendered(['start_command' => null])
        ->assertJsonValidationErrors('start_command');
});

it('drops a start command for a build that has no process', function () {
    // Storing it would leave the UI offering process controls for a site that
    // has no unit behind them.
    createRendered(['rendering_type' => 'static', 'start_command' => 'node server.js'])
        ->assertCreated()
        ->assertJsonPath('application.start_command', null)
        ->assertJsonPath('application.has_process', false);
});

it('offers the choice on the git card, with the fields that depend on it', function () {
    $git = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'git');
    $fields = collect($git['fields']);

    $rendering = $fields->firstWhere('name', 'rendering_type');

    // A PHP repository is what this card is used for most, so it is one of the
    // answers rather than something the user has to know to leave blank.
    expect(collect($rendering['options'])->pluck('value')->all())->toBe(['php', 'ssr', 'csr', 'static'])
        // The form hides these rather than collecting answers it would refuse.
        ->and($fields->firstWhere('name', 'start_command')['depends_on'])->toBe('rendering_type')
        ->and($fields->firstWhere('name', 'app_port')['depends_on'])->toBe('rendering_type');
});

it('follows the rendering type when it changes', function () {
    $id = createRendered()->json('application.id');

    // Switching to a built-to-files type must stop the site being proxied, or
    // it 502s the moment the process is gone.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->putJson("/api/applications/{$id}", ['rendering_type' => 'csr'])
        ->assertOk()
        ->assertJsonPath('application.serving_profile', 'static')
        // The command and the port go with it: keeping them would show
        // process controls with no unit behind them, and hold a port the next
        // application could have had.
        ->assertJsonPath('application.start_command', null)
        ->assertJsonPath('application.app_port', null);
});

describe('the resolver', function () {
    it('prefers the rendering type over the start command', function () {
        // The rendering type is the user saying what they built; a start
        // command is only a fallback for types that have no rendering type at
        // all, like the one-click Node installers.
        expect(ServingProfile::resolve(null, ['rendering_type' => 'ssr']))->toBe('node')
            ->and(ServingProfile::resolve(null, ['rendering_type' => 'csr', 'start_command' => 'node x.js']))->toBe('static');
    });

    it('serves a PHP repository through the PHP stack', function () {
        // The card is used for Laravel far more often than for Node, and that
        // is neither built to files nor run as a process.
        expect(ServingProfile::resolve(null, ['rendering_type' => 'php']))->toBe('php');
    });

    it('keeps the current profile when neither is mentioned', function () {
        // A partial update that touches neither must not silently reshape how
        // the site is served.
        expect(ServingProfile::resolve(null, ['name' => 'Renamed'], 'node'))->toBe('node');
    });
});

/*
 * The deploy script has to be settable at creation, not only afterwards on the
 * Deployment screen. The first deploy runs automatically once provisioning
 * finishes, so a script added later has already missed the deploy that decides
 * whether the site comes up at all.
 */
it('stores a deploy script given at creation', function () {
    createRendered(['deploy_script' => "npm ci\nnpm run build"])->assertCreated();

    expect(Application::where('name', 'App')->first()->deploy_script)
        ->toBe("npm ci\nnpm run build");
});

it('normalises Windows line endings in a deploy script', function () {
    // `sh` reads the \r as part of the command, producing "command not found:
    // npm\r" — an error that is invisible in a log. The Deployment screen
    // already normalises this; creating a site has to do the same.
    createRendered(['deploy_script' => "npm ci\r\nnpm run build"])->assertCreated();

    expect(Application::where('name', 'App')->first()->deploy_script)
        ->toBe("npm ci\nnpm run build")
        ->not->toContain("\r");
});

it('publishes the deploy script as a field on the git site type', function () {
    // The frontend renders this form from the API, so a field the schema does
    // not list is a field the user never sees.
    $fields = test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->getJson('/api/site-types')
        ->assertOk()
        ->json();

    $git = collect(data_get($fields, 'site_types', $fields))
        ->firstWhere('name', 'git');

    expect(collect($git['fields'])->pluck('name'))->toContain('deploy_script');
});
