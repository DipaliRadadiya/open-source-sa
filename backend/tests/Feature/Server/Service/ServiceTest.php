<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    // Point php-fpm detection at an empty temp dir so tests are deterministic.
    $this->phpDir = sys_get_temp_dir().'/sv-oss-php-'.uniqid();
    File::ensureDirectoryExists($this->phpDir);
    config(['server.php_dir' => $this->phpDir]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

/**
 * Fake systemctl: `show` reports each unit's state from $units (default:
 * not-found → excluded); all other systemctl commands succeed.
 *
 * @param  array<string, array{load: string, active: string, file: string}>  $units
 */
function fakeServices(array $units): void
{
    Process::fake(function ($process) use ($units) {
        if (($process->command[1] ?? null) === 'show') {
            $unit = $process->command[2] ?? '';
            $s = $units[$unit] ?? ['load' => 'not-found', 'active' => 'inactive', 'file' => 'disabled'];

            return Process::result(output: "LoadState={$s['load']}\nActiveState={$s['active']}\nUnitFileState={$s['file']}\n");
        }

        return Process::result(exitCode: 0);
    });
}

it('lists only installed services with live status, protection and actions', function () {
    fakeServices([
        'nginx' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled'],
        'mariadb' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/services')->assertOk();

    $keys = collect($response->json('services'))->pluck('key');
    expect($keys)->toContain('nginx', 'mariadb');
    // not-installed units are excluded
    expect($keys)->not->toContain('apache', 'mysql', 'redis');

    $nginx = collect($response->json('services'))->firstWhere('key', 'nginx');
    expect($nginx['protected'])->toBeTrue();               // default protected
    expect($nginx['actions'])->toBe(['restart', 'reload', 'enable']);
    expect($nginx['status'])->toBe('active');

    $mariadb = collect($response->json('services'))->firstWhere('key', 'mariadb');
    expect($mariadb['protected'])->toBeFalse();
    expect($mariadb['actions'])->toContain('start', 'stop', 'disable');
});

it('auto-detects installed php-fpm versions from php_dir', function () {
    File::ensureDirectoryExists($this->phpDir.'/8.4/fpm');
    fakeServices([
        'php8.4-fpm' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/services')->assertOk();

    $php = collect($response->json('services'))->firstWhere('key', 'php8.4-fpm');
    expect($php)->not->toBeNull();
    expect($php['label'])->toBe('PHP 8.4 FPM');
    expect($php['protected'])->toBeTrue(); // php8.4-fpm is in the default protected set
});

it('restarts a service via systemctl', function () {
    fakeServices(['mariadb' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/services/mariadb', ['action' => 'restart'])
        ->assertOk()
        ->assertJsonPath('service.key', 'mariadb');

    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'restart', 'mariadb']);
});

it('blocks stopping a protected service', function () {
    fakeServices(['nginx' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/services/nginx', ['action' => 'stop'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action');

    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'stop', 'nginx']);
});

it('returns 404 for a service that is not installed', function () {
    fakeServices([]); // nothing installed

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/services/apache', ['action' => 'restart'])
        ->assertNotFound();
});

it('returns 404 for an unknown service key', function () {
    fakeServices([]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/services/not-a-service', ['action' => 'restart'])
        ->assertNotFound();
});

it('returns a translated error with reference when the action fails', function () {
    Process::fake(function ($process) {
        if (($process->command[1] ?? null) === 'show') {
            return Process::result(output: "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n");
        }

        return Process::result(output: '', errorOutput: 'boom', exitCode: 1);
    });

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/services/mariadb', ['action' => 'restart'])
        ->assertStatus(500)
        ->assertJsonStructure(['message', 'reference']);
});

it('rejects an invalid action', function () {
    fakeServices(['mariadb' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/services/mariadb', ['action' => 'frobnicate'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action');
});

it('denies a viewer without manage from running an action', function () {
    fakeServices(['mariadb' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);
    $viewer = User::factory()->create();
    grantPermission($viewer, 'service', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/services/mariadb', ['action' => 'restart'])
        ->assertForbidden();
});
