<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A writable temp dir standing in for /var/log, wired into the registry.
    $this->logDir = sys_get_temp_dir().'/sv-oss-logs-'.uniqid();
    File::ensureDirectoryExists($this->logDir);

    config(['server.logs' => [
        ['key' => 'nginx_error', 'label' => 'Nginx — Error', 'group' => 'web', 'path' => $this->logDir.'/nginx-error.log'],
        ['key' => 'syslog', 'label' => 'System — Syslog', 'group' => 'system', 'path' => $this->logDir.'/syslog'],
    ]]);
    // No php-fpm logs bleeding in from the real /etc/php.
    config(['server.php_dir' => $this->logDir.'/empty-php']);
    File::ensureDirectoryExists($this->logDir.'/empty-php');
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
});

it('lists only log sources whose file exists, with metadata', function () {
    File::put($this->logDir.'/nginx-error.log', "one\ntwo\n");
    // syslog file intentionally not created → excluded.

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs')->assertOk();

    $keys = collect($response->json('logs'))->pluck('key');
    expect($keys)->toContain('nginx_error');
    expect($keys)->not->toContain('syslog');

    $nginx = collect($response->json('logs'))->firstWhere('key', 'nginx_error');
    expect($nginx['group'])->toBe('web');
    expect($nginx['readable'])->toBeTrue();
    expect($nginx['size'])->toBeGreaterThan(0);
});

it('tails the last N lines of a source', function () {
    File::put($this->logDir.'/nginx-error.log', collect(range(1, 50))->map(fn ($i) => "line {$i}")->implode("\n")."\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/nginx_error?lines=5')->assertOk();

    expect($response->json('log.lines'))->toBe(['line 46', 'line 47', 'line 48', 'line 49', 'line 50']);
    expect($response->json('log.truncated'))->toBeTrue();
    expect($response->json('log.cursor'))->toBeGreaterThan(0);
});

it('filters lines with a literal grep', function () {
    File::put($this->logDir.'/nginx-error.log', "info start\nERROR boom\ninfo tick\nerror again\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/nginx_error?grep=error')->assertOk();

    // case-insensitive literal match
    expect($response->json('log.lines'))->toBe(['ERROR boom', 'error again']);
});

it('returns only bytes appended since the cursor', function () {
    File::put($this->logDir.'/nginx-error.log', "a\nb\n");
    $first = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/nginx_error')->assertOk();
    $cursor = $first->json('log.cursor');

    File::append($this->logDir.'/nginx-error.log', "c\nd\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson("/api/logs/nginx_error?after={$cursor}")->assertOk();
    expect($response->json('log.lines'))->toBe(['c', 'd']);
});

it('returns 404 for an unknown source key', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/not-a-log')->assertNotFound();
});

it('returns 404 when the source is registered but the file is missing', function () {
    // syslog is registered but never created.
    $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/syslog')->assertNotFound();
});

it('downloads a log file and records the activity', function () {
    File::put($this->logDir.'/nginx-error.log', "downloadable\n");

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->get('/api/logs/nginx_error/download')
        ->assertOk()
        ->assertDownload('nginx_error.log');

    expect(ActivityLog::where('type', 'log')->where('action', 'downloaded')->exists())->toBeTrue();
});

it('denies a user without the logs permission', function () {
    File::put($this->logDir.'/nginx-error.log', "secret\n");
    $stranger = User::factory()->create();
    $token = $stranger->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/logs')->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/logs/nginx_error')->assertForbidden();
});

it('allows a viewer with the logs permission', function () {
    File::put($this->logDir.'/nginx-error.log', "visible\n");
    $viewer = User::factory()->create();
    grantPermission($viewer, 'logs', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/logs/nginx_error')
        ->assertOk()
        ->assertJsonPath('log.lines', ['visible']);
});
