<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A Node application is reachable from a browser.
 *
 * The process ran and the port was real before this, but every vhost still
 * served the site's directory — so a visitor got a file listing or a 403 while
 * the app sat there answering nobody.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->su = SystemUser::create([
        'username' => 'nodeuser', 'home_path' => '/home/nodeuser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function useWebServer(string $webServer): void
{
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'mern', 'web_server' => $webServer,
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);
}

function proxyApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'API', 'domain' => 'api.test',
        'site_type' => 'git', 'serving_profile' => 'node', 'status' => 'active',
        'web_root' => '/', 'app_port' => 3210, 'start_command' => 'node server.js',
    ], $overrides));
}

it('serves a Node app by proxy on nginx, websockets included', function () {
    useWebServer('nginx');
    Process::fake(fn () => Process::result(output: ''));

    $config = app(WebServerManager::class)->driver()
        ->renderConfig(proxyApp(), '/home/nodeuser/api.test');

    expect($config)
        ->toContain('proxy_pass http://127.0.0.1:3210;')
        ->toContain('proxy_http_version 1.1;')
        // Without the Upgrade pair a WebSocket handshake is answered with a
        // plain 200 and the client waits forever.
        ->toContain('proxy_set_header Upgrade $http_upgrade;')
        // The map-based recipe needs an http-block declaration we do not own;
        // referencing it here would fail the config test outright.
        ->toContain('proxy_set_header Connection $http_connection;')
        ->not->toContain('$connection_upgrade')
        // Otherwise the app sees every visitor as 127.0.0.1 over http.
        ->toContain('proxy_set_header X-Forwarded-Proto $scheme;');
});

it('serves a Node app by proxy on Apache, upgrades before the catch-all', function () {
    useWebServer('apache');
    Process::fake(fn () => Process::result(output: ''));

    $config = app(WebServerManager::class)->driver()
        ->renderConfig(proxyApp(), '/home/nodeuser/api.test');

    // ProxyPass on / would swallow the upgrade as ordinary HTTP, so the
    // WebSocket rule has to come first. Order is the whole trick.
    expect(strpos($config, 'RewriteCond %{HTTP:Upgrade} =websocket'))
        ->toBeLessThan(strpos($config, 'ProxyPass        / http://127.0.0.1:3210/'));

    expect($config)->toContain('ProxyPreserveHost On');
});

it('serves a Node app by proxy on OpenLiteSpeed, as an external app', function () {
    useWebServer('openlitespeed');
    Process::fake(fn () => Process::result(output: ''));

    $config = app(WebServerManager::class)->driver()
        ->renderConfig(proxyApp(), '/home/nodeuser/api.test');

    expect($config)
        // OLS proxies by declaring the backend as an external application and
        // pointing a context at it — there is no proxy_pass equivalent.
        ->toContain('type                    proxy')
        ->toContain('address                 http://127.0.0.1:3210')
        ->toContain('handler                 sv-app-')
        // The websocket block takes host:port with no scheme, unlike the
        // extprocessor address above it.
        ->toContain('address                 127.0.0.1:3210');
});

it('gives every application its own backend name', function () {
    useWebServer('openlitespeed');
    Process::fake(fn () => Process::result(output: ''));

    $driver = app(WebServerManager::class)->driver();
    $one = proxyApp(['domain' => 'one.test', 'app_port' => 3211]);
    $two = proxyApp(['domain' => 'two.test', 'app_port' => 3212]);

    // OLS declares external applications by name. Two sites sharing one means
    // the second silently overwrites the first's backend.
    expect($driver->renderConfig($one, '/home/nodeuser/one.test'))
        ->toContain("sv-app-{$one->id}")
        ->and($driver->renderConfig($two, '/home/nodeuser/two.test'))
        ->toContain("sv-app-{$two->id}");
});

it('switches an application to proxy serving when it gains a process', function () {
    Process::fake(fn () => Process::result(output: ''));

    $created = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/applications', [
            'system_user_id' => $this->su->id, 'name' => 'Plain', 'domain' => 'plain.test',
            'site_type' => 'php',
        ])->assertCreated()->json('application');

    expect($created['serving_profile'])->toBe('php');

    // Serving the directory for an app that routes in code publishes its
    // source; serving by proxy with nothing behind it is a 502. The profile
    // has to follow the start command.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->putJson("/api/applications/{$created['id']}", ['start_command' => 'node server.js'])
        ->assertOk()
        ->assertJsonPath('application.serving_profile', 'node');
});

it('switches back when the process is taken away', function () {
    Process::fake(fn () => Process::result(output: ''));
    $app = proxyApp(['site_type' => 'php', 'domain' => 'back.test']);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->putJson("/api/applications/{$app->id}", ['start_command' => null])
        ->assertOk()
        ->assertJsonPath('application.serving_profile', 'php');
});
