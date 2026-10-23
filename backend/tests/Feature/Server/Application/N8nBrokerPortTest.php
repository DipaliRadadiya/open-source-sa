<?php

use App\Models\Application;
use App\Models\AppPackageRelease;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\Installers\N8nInstaller;
use App\Services\Server\Applications\PortAllocator;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * FS-C22 #8: every n8n listened for its task runners on 127.0.0.1:5679, n8n's
 * default, so a second one on the server found it taken and exited on start.
 * n8n 2.44's broker, read on the test server:
 *
 *     if (error.code === 'EADDRINUSE') {
 *         this.logger.info(`n8n Task Broker's port ${port} is already in use. …`);
 *         process.exit(1);
 *
 * Each new n8n now gets its own port, from the same allocator as `app_port`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'flows', 'home_path' => '/home/flows', 'shell' => '/bin/bash', 'sudo' => false]);

    Queue::fake();
    fakeUsableSqlEngine();

    ServerCapability::create([
        'stack' => 'mern', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    AppPackageRelease::create(['package' => 'n8n', 'version' => '2.44.6', 'node_range' => '>=24.0.0']);
});

function brokerHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function createN8n(string $name): array
{
    return test()->withHeaders(brokerHeaders())->postJson('/api/applications', [
        'site_type' => 'n8n',
        'name' => $name,
        'domain' => strtolower($name).'.example.com',
        'system_user_id' => test()->su->id,
        'admin_email' => 'owner@example.com',
        'admin_password' => 'Flows-Pass-2026',
        'node_version' => '25',
    ])->assertCreated()->json('application');
}

it('gives every new n8n its own task-broker port, apart from every other port', function () {
    $first = createN8n('One');
    $second = createN8n('Two');

    $ports = [$first['app_port'], $first['internal_port'], $second['app_port'], $second['internal_port']];

    expect($ports)->each->toBeInt()
        ->and(array_unique($ports))->toHaveCount(4)
        ->and(Application::find($second['id'])->internal_port)->toBe($second['internal_port']);
});

it('gives no second port to a site type that does not need one', function () {
    $nodeRed = $this->withHeaders(brokerHeaders())->postJson('/api/applications', [
        'site_type' => 'nodered', 'name' => 'Red', 'domain' => 'red.example.com',
        'system_user_id' => $this->su->id, 'admin_username' => 'admin', 'admin_password' => 'Red-Pass-2026',
        'node_version' => '24',
    ])->assertCreated()->json('application');

    expect($nodeRed['app_port'])->toBeInt()
        ->and($nodeRed['internal_port'])->toBeNull()
        ->and(app(SiteTypeManager::class)->find('nodered')->needsInternalPort())->toBeFalse()
        ->and(app(SiteTypeManager::class)->find('n8n')->needsInternalPort())->toBeTrue();
});

it('does not hand out a stopped n8n\'s broker port, which nothing is listening on', function () {
    // Stopped, it binds neither port, so `ss` cannot see them.
    Process::fake(fn () => Process::result(output: ''));
    Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Old', 'slug' => 'old', 'domain' => 'old.test',
        'site_type' => 'n8n', 'serving_profile' => 'node', 'status' => 'active', 'web_root' => '/',
        'app_port' => 3000, 'internal_port' => 3001,
    ]);

    expect(app(PortAllocator::class)->allocate())->toBe(3002)
        // And not one the caller has just picked and not saved.
        ->and(app(PortAllocator::class)->allocate([3002]))->toBe(3003);
});

it('refuses another site\'s broker port as an app port', function () {
    Process::fake(fn () => Process::result(output: ''));
    Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Old', 'slug' => 'old', 'domain' => 'old.test',
        'site_type' => 'n8n', 'serving_profile' => 'node', 'status' => 'active', 'web_root' => '/',
        'app_port' => 3600, 'internal_port' => 3601,
    ]);

    $this->withHeaders(brokerHeaders())->postJson('/api/applications', [
        'system_user_id' => $this->su->id, 'name' => 'Api', 'domain' => 'api.test',
        'site_type' => 'php', 'app_port' => 3601,
    ])->assertStatus(422)->assertJsonValidationErrors(['app_port' => __('validation.port_in_use_by_app', ['port' => 3601])]);

    expect(app(PortAllocator::class)->conflict(3601))->toBe('in_use_by_app');
});

it('writes the broker port into the site\'s environment, and leaves an older n8n on its default', function () {
    Process::fake(fn () => Process::result(output: ''));

    $site = fn (string $slug, int $appPort, ?int $internal) => Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => $slug, 'slug' => $slug, 'domain' => "{$slug}.test",
        'site_type' => 'n8n', 'serving_profile' => 'node', 'status' => 'pending', 'web_root' => '/',
        'app_port' => $appPort, 'internal_port' => $internal, 'node_version' => '24',
    ]);

    // The private writer install() pipes to the site's `.env`.
    $installer = app(N8nInstaller::class);
    $environment = new ReflectionMethod($installer, 'environment');

    $new = $environment->invoke($installer, $site('fresh', 3300, 3301), '/home/flows/fresh/public_html');
    $old = $environment->invoke($installer, $site('older', 3302, null), '/home/flows/older/public_html');

    expect($new)->toContain('N8N_RUNNERS_BROKER_PORT="3301"')
        ->toContain('N8N_PORT="3300"')
        // New sites only (2026-10-03): an existing n8n is running on 5679 and
        // keeps it; nothing rewrites its environment to a port it never had.
        ->and($old)->not->toContain('N8N_RUNNERS_BROKER_PORT')
        // Not editable on the Environment screen: the panel owns it, and a
        // changed value would move the broker under a port nothing reserved.
        ->and(app(SiteTypeManager::class)->find('n8n')->panelManagedEnvironmentKeys())->toContain('N8N_RUNNERS_BROKER_PORT');
});
