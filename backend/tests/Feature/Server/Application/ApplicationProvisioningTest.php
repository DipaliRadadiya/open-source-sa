<?php

use App\Exceptions\Server\Application\UnsupportedWebServerException;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
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

    config([
        'server.web_server_drivers.nginx.sites_dir' => '/etc/nginx/sites-enabled',
        // The real file lives here; sites-enabled holds a symlink to it.
        // Unset, every assertion about the written config compared against a
        // path beginning with a bare slash and quietly matched nothing.
        'server.web_server_drivers.nginx.sites_available_dir' => '/etc/nginx/sites-available',
    ]);
});

function makeApp(array $overrides = []): Application
{
    // `forceCreate`, because `slug` is not fillable — the create action
    // assigns it. Through `create()` the slug was silently dropped and every
    // path in this file came out as `{home}/public_html`, which is the
    // pre-slug layout no server has used since August.
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        // Named by slug, never domain. Every real application has one — the
        // create action assigns it — and `rootPath()` only falls back to the
        // home directory for rows written before the column existed. Without
        // it here the fixture described a layout the panel stopped using in
        // August, and these assertions pinned the old one.
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
    ], $overrides));
}

function provisionHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('creates the directory, writes a tested config and reloads', function () {
    Process::fake();
    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();
    expect($app->status->value)->toBe('active');
    expect($app->steps)->toBe([
        'create_directory', 'placeholder', 'set_ownership', 'create_php_pool', 'write_config', 'test_config', 'reload',
    ]);

    // `{home}/{slug}/public_html` — the document root, not the site directory:
    // `.panel`, the logs and the `.env` live beside it and must not be served.
    Process::assertRan(fn ($p) => $p->command === ['mkdir', '-p', '/home/deploy/shop/public_html']);
    Process::assertRan(fn ($p) => $p->command === ['chown', '-R', 'deploy:deploy', '/home/deploy/shop/public_html']);
    // Named by slug, and written to sites-available — sites-enabled gets the
    // symlink. The domain-named file is what a row predating the slug column
    // falls back to, not what a current site produces.
    Process::assertRan(fn ($p) => $p->command === ['tee', '/etc/nginx/sites-available/shop.conf']
        && str_contains($p->input, 'server_name shop.example.com')
        && str_contains($p->input, 'root /home/deploy/shop/public_html;')
        // Its OWN pool socket, named for the slug — not the server-wide
        // php8.4-fpm.sock. That is the whole point of per-site isolation: a
        // vhost still pointing at the shared socket is a site running as
        // www-data with none of its own settings.
        && str_contains($p->input, '/run/php/shop.sock'));
    Process::assertRan(fn ($p) => $p->command === ['nginx', '-t']);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'nginx']);
});

it('removes the config and does not reload when the config test fails', function () {
    // A broken vhost that reaches a reload takes every other site down.
    Process::fake(fn ($process) => $process->command === ['nginx', '-t']
        ? Process::result(errorOutput: 'invalid directive', exitCode: 1)
        : Process::result(exitCode: 0));

    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();
    expect($app->status->value)->toBe('failed');
    expect($app->failed_step)->toBe('test_config');
    expect($app->reference)->not->toBeEmpty();

    // Our config is taken back out, and nothing is reloaded.
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.conf']);
    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'reload', 'nginx']);
});

it('stops at the first failed step and records where it broke', function () {
    Process::fake(fn ($process) => $process->command[0] === 'mkdir'
        ? Process::result(errorOutput: 'permission denied', exitCode: 1)
        : Process::result(exitCode: 0));

    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();
    expect($app->status->value)->toBe('failed');
    expect($app->failed_step)->toBe('create_directory');
    // Nothing further was attempted.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('never leaks the raw server error into the response', function () {
    Process::fake(fn ($process) => $process->command === ['nginx', '-t']
        ? Process::result(errorOutput: 'nginx: [emerg] secret internal path /etc/foo', exitCode: 1)
        : Process::result(exitCode: 0));

    $app = makeApp();
    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $body = $this->withHeaders(provisionHeaders())->getJson("/api/applications/{$app->id}")->json();

    expect(json_encode($body))->not->toContain('secret internal path');
    expect($body['application']['reference'])->not->toBeEmpty();
});

it('is idempotent when the job runs twice', function () {
    Process::fake();
    $app = makeApp();

    foreach (range(1, 2) as $ignored) {
        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );
    }

    $app->refresh();
    expect($app->status->value)->toBe('active');
    // mkdir -p and an overwriting tee converge rather than compounding.
    expect($app->steps)->toHaveCount(7);
});

it('writes a static config without a php handler', function () {
    Process::fake();
    $app = makeApp(['site_type' => 'static', 'serving_profile' => 'static', 'php_version' => null]);

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        // Content is written to sites-available; sites-enabled only gets a
        // symlink to it (see AbstractWebServerDriver::apply()).
        && str_contains((string) $p->command[1], 'sites-available')
        && ! str_contains($p->input, 'fastcgi_pass'));
});

it('refuses to provision when the web server is not one we can configure', function () {
    Process::fake();
    // Was `openlitespeed`, which the panel can now configure. The rule this
    // guards is unchanged — a web server with no driver is refused rather
    // than guessed at — so it needs a web server that genuinely has none.
    ServerCapability::query()->update(['web_server' => 'caddy']);

    $app = makeApp();

    expect(fn () => app(ApplicationProvisioner::class)->provision($app->load('systemUser')))
        ->toThrow(UnsupportedWebServerException::class);

    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('queues provisioning when an application is created', function () {
    Queue::fake();

    $this->withHeaders(provisionHeaders())->postJson('/api/applications', [
        'site_type' => 'php',
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'system_user_id' => $this->su->id,
    ])->assertCreated();

    Queue::assertPushed(ProvisionApplication::class);
});

it('can retry a failed provision explicitly', function () {
    Queue::fake();
    $app = makeApp(['status' => 'failed', 'failed_step' => 'reload', 'reference' => 'abc']);

    $this->withHeaders(provisionHeaders())
        ->postJson("/api/applications/{$app->id}/provision")
        ->assertStatus(202)
        ->assertJsonPath('application.status', 'provisioning');

    Queue::assertPushed(ProvisionApplication::class);
    // The previous failure is cleared so the UI doesn't show a stale error.
    expect($app->fresh()->failed_step)->toBeNull();
});

it('does not queue a second provision while one is already running', function () {
    Queue::fake();
    $app = makeApp(['status' => 'provisioning', 'steps' => ['create_directory']]);

    $this->withHeaders(provisionHeaders())
        ->postJson("/api/applications/{$app->id}/provision")
        ->assertStatus(202)
        ->assertJsonPath('application.status', 'provisioning');

    Queue::assertNothingPushed();
    expect($app->fresh()->steps)->toBe(['create_directory']);
});

it('uses one unique provisioning job per application', function () {
    expect((new ProvisionApplication(42))->uniqueId())->toBe('application-provision-42');
});

it('keeps a support reference when the provisioning worker dies', function () {
    $app = makeApp(['status' => 'provisioning']);

    (new ProvisionApplication($app->id))->failed(new RuntimeException('worker lost'));

    $app->refresh();
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('worker')
        ->and($app->reference)->not->toBeEmpty();
});

it('refuses to delete an application while provisioning is running', function () {
    Process::fake();
    $app = makeApp(['status' => 'provisioning']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertStatus(503);

    expect(Application::find($app->id))->not->toBeNull();
    Process::assertNothingRan();
});

it('removes the site config on delete but keeps the files by default', function () {
    Process::fake();
    $app = makeApp(['status' => 'active']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.conf']);
    // Someone's code must never disappear as a side effect of removing a record.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'rm' && ($p->command[1] ?? '') === '-rf');
    expect(Application::count())->toBe(0);
});

it('removes the files only when explicitly asked', function () {
    Process::fake();
    $app = makeApp(['status' => 'active']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_files=true")
        ->assertOk();

    // The whole site directory, not just what was served.
    Process::assertRan(fn ($p) => $p->command === ['rm', '-rf', '/home/deploy/shop']);
});

it('touches nothing on the server when deleting an app that was never provisioned', function () {
    Process::fake();
    $app = makeApp(['status' => 'pending']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk();

    Process::assertNothingRan();
});

it('denies provisioning with view-only access', function () {
    Queue::fake();
    $user = User::factory()->create();
    grantPermission($user, 'application', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;
    $app = makeApp(['status' => 'failed']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/applications/{$app->id}/provision")
        ->assertForbidden();

    Queue::assertNothingPushed();
});
