<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->phpDir = sys_get_temp_dir().'/sv-oss-svc-'.getmypid();
    File::deleteDirectory($this->phpDir);
    File::makeDirectory("{$this->phpDir}/8.4/fpm", 0755, true);

    config(['server.php_dir' => $this->phpDir]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

/** Every unit installed and running, so `describe()` returns them. */
function fakeSystemd(): void
{
    Process::fake(fn ($process) => match (true) {
        ($process->command[0] ?? '') === 'systemctl' && ($process->command[1] ?? '') === 'show' => Process::result(output: "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n"),
        default => Process::result(exitCode: 0),
    });
}

function svcHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('reports which services can validate their own configuration', function () {
    fakeSystemd();

    $response = $this->withHeaders(svcHeaders())->getJson('/api/services');

    $response->assertOk();
    $services = collect($response->json('services'))->keyBy('key');

    expect($services['nginx']['testable'])->toBeTrue();
    expect($services['php8.4-fpm']['testable'])->toBeTrue();
    // Redis has no config test, so the UI shouldn't offer one.
    expect($services['redis']['testable'] ?? false)->toBeFalse();
});

it('runs the right test command per service', function () {
    fakeSystemd();

    $this->withHeaders(svcHeaders())->postJson('/api/services/nginx/config-test')
        ->assertOk()->assertJsonPath('config_test.ok', true);

    Process::assertRan(fn ($p) => $p->command === ['nginx', '-t']);

    $this->withHeaders(svcHeaders())->postJson('/api/services/php8.4-fpm/config-test')->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['/usr/sbin/php-fpm8.4', '-t']);
});

it('reports a failing configuration without reloading anything', function () {
    Process::fake(fn ($process) => match (true) {
        ($process->command[0] ?? '') === 'systemctl' && ($process->command[1] ?? '') === 'show' => Process::result(output: "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n"),
        $process->command === ['nginx', '-t'] => Process::result(errorOutput: 'nginx: [emerg] unknown directive "foo" in /etc/nginx/x.conf:3', exitCode: 1),
        default => Process::result(exitCode: 0),
    });

    $response = $this->withHeaders(svcHeaders())->postJson('/api/services/nginx/config-test');

    $response->assertOk();
    $response->assertJsonPath('config_test.ok', false);
    // The output names the offending file and line — that is the whole point,
    // and it describes the user's own config, not our internals.
    expect($response->json('config_test.output'))->toContain('unknown directive');

    // Testing must never apply anything.
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'systemctl' && ($p->command[1] ?? '') === 'reload');
});

it('refuses to invent a test for a service that has none', function () {
    fakeSystemd();

    $this->withHeaders(svcHeaders())->postJson('/api/services/redis/config-test')->assertStatus(422);
});

it('returns 404 for an unknown service', function () {
    fakeSystemd();

    $this->withHeaders(svcHeaders())->postJson('/api/services/nonsense/config-test')->assertNotFound();
});

it('denies a config test without the service permission', function () {
    fakeSystemd();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/services/nginx/config-test')
        ->assertForbidden();
});
