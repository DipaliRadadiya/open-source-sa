<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // Temp "service logs" pointed at a writable dir → service_logs is the one
    // fully-deterministic target (its estimate reads real filesizes).
    $this->logDir = sys_get_temp_dir().'/sv-oss-dc-'.uniqid();
    File::ensureDirectoryExists($this->logDir);
    File::put($this->logDir.'/access.log', str_repeat('x', 4096));
    File::put($this->logDir.'/error.log', str_repeat('y', 2048));
    config(['server.disk_cleaner.service_log_globs' => [$this->logDir.'/*.log']]);
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
});

/**
 * Default fake: df returns a fixed filesystem; estimate/clean commands succeed
 * with benign output so any host-present target (apt/journal/find) is safe.
 */
function fakeDisk(): void
{
    Process::fake(function ($process) {
        return match ($process->command[0] ?? '') {
            'df' => Process::result(output: "Filesystem 1B-blocks Used Available Capacity Mounted\n/dev/vda1 100000000000 60000000000 40000000000 60% /\n"),
            'du' => Process::result(output: "1048576\t/path"),
            'journalctl' => Process::result(output: 'Archived and active journals take up 200.0M in the file system.'),
            'apt-get' => Process::result(output: 'After this operation, 50.0 MB disk space will be freed.'),
            'find' => Process::result(output: "1024\n2048\n"),
            default => Process::result(exitCode: 0),
        };
    });
}

it('previews disk usage and available categories with paths', function () {
    fakeDisk();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/disk-cleaner')->assertOk();

    $response->assertJsonPath('disk.percent', 60)
        ->assertJsonPath('disk.total', 100000000000);

    $service = collect($response->json('categories'))->firstWhere('key', 'service_logs');
    expect($service)->not->toBeNull();
    expect($service['method'])->toBe('truncate');
    expect($service['group'])->toBe('logs');
    expect($service['reclaimable'])->toBe(6144);              // 4096 + 2048
    expect($service['paths'])->toContain($this->logDir.'/access.log');
});

it('cleans selected categories, truncating service logs and logging activity', function () {
    fakeDisk();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs']])
        ->assertOk()
        ->assertJsonPath('cleaned.0.key', 'service_logs')
        ->assertJsonStructure(['disk', 'cleaned' => [['key', 'freed', 'freed_human']], 'freed_total']);

    Process::assertRan(fn ($p) => $p->command[0] === 'truncate'
        && in_array($this->logDir.'/access.log', $p->command, true));

    expect(ActivityLog::where('type', 'disk_cleaner')->where('action', 'cleaned')->exists())->toBeTrue();
});

it('rejects an unknown / unavailable category', function () {
    fakeDisk();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/disk-cleaner/clean', ['categories' => ['not_a_category']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('categories.0');
});

it('requires at least one category', function () {
    fakeDisk();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/disk-cleaner/clean', ['categories' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('categories');
});

it('returns a translated error with reference when the clean fails', function () {
    Process::fake(function ($process) {
        return match ($process->command[0] ?? '') {
            'df' => Process::result(output: "fs 1B-blocks Used Avail Cap Mount\n/dev/vda1 100 60 40 60% /\n"),
            'truncate' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
            default => Process::result(exitCode: 0),
        };
    });

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs']])
        ->assertStatus(500)
        ->assertJsonStructure(['message', 'reference']);
});

it('excludes service_logs when no matching files exist', function () {
    config(['server.disk_cleaner.service_log_globs' => [$this->logDir.'/none/*.log']]);
    fakeDisk();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/disk-cleaner')->assertOk();

    expect(collect($response->json('categories'))->pluck('key'))->not->toContain('service_logs');
});

it('denies a viewer without manage from cleaning', function () {
    fakeDisk();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'disk_cleaner', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs']])
        ->assertForbidden();
});

it('denies a user without the permission from previewing', function () {
    fakeDisk();
    $stranger = User::factory()->create();
    $token = $stranger->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/disk-cleaner')->assertForbidden();
});
