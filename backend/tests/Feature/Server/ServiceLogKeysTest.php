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

it('links MariaDB to its journal log, where Ubuntu\'s MariaDB writes', function () {
    config([
        'server.services' => [['key' => 'mariadb', 'unit' => 'mariadb', 'label' => 'MariaDB']],
        // The shipped mapping, not a copy of it: that is what was wrong.
        'server.service_logs' => (require config_path('server.php'))['service_logs'],
        'server.logs' => [
            ['key' => 'mysql_error', 'label' => 'MySQL — Error', 'group' => 'database', 'path' => "{$this->logDir}/mysql-error.log"],
            ['key' => 'mariadb', 'label' => 'MariaDB', 'group' => 'database', 'kind' => 'journal', 'identifier' => 'mariadbd', 'path' => '', 'hide_when_empty' => true],
        ],
    ]);
    // The service probe and journalctl both answer; only the latter matters here.
    Process::fake(fn ($p) => str_contains(implode(' ', (array) $p->command), 'journalctl')
        ? Process::result(output: "Oct 15 mariadbd[1]: ready for connections.\n")
        : Process::result(output: "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n"));

    $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/services')
        ->assertJsonPath('services.0.key', 'mariadb')
        ->assertJsonPath('services.0.log_keys', ['mariadb']);
});

it('links PostgreSQL to the log of each cluster on the server, and to nothing when there is none', function () {
    config([
        'server.services' => [['key' => 'postgresql', 'unit' => 'postgresql', 'label' => 'PostgreSQL']],
        'server.postgres_log_dir' => $this->logDir,
    ]);

    $keys = fn () => $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/services')->json('services.0.log_keys');

    expect($keys())->toBe([]);

    File::put("{$this->logDir}/postgresql-18-main.log", "LOG: ready\n");
    File::put("{$this->logDir}/postgresql-16-main.log", "LOG: ready\n");

    expect($keys())->toEqualCanonicalizing(['postgresql_16_main', 'postgresql_18_main']);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/logs/postgresql_18_main')
        ->assertOk();
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
