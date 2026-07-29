<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->logDir = sys_get_temp_dir().'/sv-oss-svc-logs-'.getmypid();
    File::deleteDirectory($this->logDir);
    File::makeDirectory($this->logDir, 0755, true);
    File::put("{$this->logDir}/error.log", "an error\n");

    config([
        'server.php_dir' => '/nonexistent-php-dir',
        'server.services' => [['key' => 'nginx', 'unit' => 'nginx', 'label' => 'Nginx']],
        'server.service_logs' => ['nginx' => ['nginx_error', 'nginx_access']],
        'server.logs' => [
            ['key' => 'nginx_error', 'label' => 'Nginx — Error', 'group' => 'web', 'path' => "{$this->logDir}/error.log"],
            // Deliberately absent from disk.
            ['key' => 'nginx_access', 'label' => 'Nginx — Access', 'group' => 'web', 'path' => "{$this->logDir}/access.log"],
        ],
    ]);

    Process::fake(['*' => Process::result(output: "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n")]);
});

afterEach(fn () => File::deleteDirectory($this->logDir));

it('points a service at its log sources in the existing logs feature', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/services');

    // Only the source that exists — a button that opens an empty viewer is
    // worse than no button.
    $response->assertOk()->assertJsonPath('services.0.log_keys', ['nginx_error']);
});

it('serves that key through the logs endpoint, so the association is real', function () {
    $key = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/services')->json('services.0.log_keys.0');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/logs/{$key}")
        ->assertOk()
        ->assertJsonPath('log.key', 'nginx_error');
});

it('derives the log key for each php-fpm version', function () {
    $phpDir = sys_get_temp_dir().'/sv-oss-svc-php-'.getmypid();
    File::deleteDirectory($phpDir);
    File::makeDirectory("{$phpDir}/8.4/fpm", 0755, true);
    File::put("{$this->logDir}/php8.4-fpm.log", "fpm\n");

    config([
        'server.php_dir' => $phpDir,
        'server.php_fpm_log' => "{$this->logDir}/php{version}-fpm.log",
        'server.services' => [],
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/services');

    $response->assertJsonPath('services.0.key', 'php8.4-fpm')
        ->assertJsonPath('services.0.log_keys', ['php8.4_fpm']);

    File::deleteDirectory($phpDir);
});
