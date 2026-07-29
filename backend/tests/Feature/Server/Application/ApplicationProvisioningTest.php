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

    config(['server.web_server_drivers.nginx.sites_dir' => '/etc/nginx/sites-enabled']);
});

function makeApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
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
        'create_directory', 'set_ownership', 'placeholder', 'write_config', 'test_config', 'reload',
    ]);

    Process::assertRan(fn ($p) => $p->command === ['mkdir', '-p', '/home/deploy/shop.example.com']);
    Process::assertRan(fn ($p) => $p->command === ['chown', '-R', 'deploy:deploy', '/home/deploy/shop.example.com']);
    Process::assertRan(fn ($p) => $p->command === ['tee', '/etc/nginx/sites-enabled/shop.example.com.conf']
        && str_contains($p->input, 'server_name shop.example.com')
        && str_contains($p->input, 'root /home/deploy/shop.example.com;')
        && str_contains($p->input, 'php8.4-fpm.sock'));
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
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.example.com.conf']);
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
    expect($app->steps)->toHaveCount(6);
});

it('writes a static config without a php handler', function () {
    Process::fake();
    $app = makeApp(['site_type' => 'static', 'serving_profile' => 'static', 'php_version' => null]);

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        && str_contains((string) $p->command[1], 'sites-enabled')
        && ! str_contains($p->input, 'fastcgi_pass'));
});

it('refuses to provision when the web server is not one we can configure', function () {
    Process::fake();
    ServerCapability::query()->update(['web_server' => 'openlitespeed']);

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

it('removes the site config on delete but keeps the files by default', function () {
    Process::fake();
    $app = makeApp(['status' => 'active']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.example.com.conf']);
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

    Process::assertRan(fn ($p) => $p->command === ['rm', '-rf', '/home/deploy/shop.example.com']);
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
