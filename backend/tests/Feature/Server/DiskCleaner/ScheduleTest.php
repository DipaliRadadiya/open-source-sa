<?php

use App\Models\ActivityLog;
use App\Models\DiskCleanerRun;
use App\Models\DiskCleanerSchedule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->logDir = sys_get_temp_dir().'/sv-oss-dcs-'.uniqid();
    File::ensureDirectoryExists($this->logDir);
    File::put($this->logDir.'/access.log', str_repeat('x', 4096));
    config(['server.disk_cleaner.service_log_globs' => [$this->logDir.'/*.log']]);
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
    Carbon::setTestNow();
});

function fakeDiskAt(int $percent): void
{
    $used = $percent * 1_000_000_000;
    $total = 100_000_000_000;
    $free = $total - $used;
    Process::fake(function ($process) use ($total, $used, $free, $percent) {
        return match ($process->command[0] ?? '') {
            'df' => Process::result(output: "fs 1B-blocks Used Avail Cap Mount\n/dev/vda1 {$total} {$used} {$free} {$percent}% /\n"),
            default => Process::result(exitCode: 0),
        };
    });
}

it('returns default schedule when none is set', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/disk-cleaner/schedule')
        ->assertOk()
        ->assertJsonPath('schedule.enabled', false)
        ->assertJsonPath('schedule.frequency', 'weekly')
        ->assertJsonPath('schedule.categories', []);
});

it('saves the schedule and logs the change', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/disk-cleaner/schedule', [
            'enabled' => true,
            'frequency' => 'daily',
            'categories' => ['service_logs'],
            'threshold_percent' => 80,
        ])
        ->assertOk()
        ->assertJsonPath('schedule.enabled', true)
        ->assertJsonPath('schedule.frequency', 'daily')
        ->assertJsonPath('schedule.threshold_percent', 80);

    expect(DiskCleanerSchedule::query()->count())->toBe(1);
    expect(ActivityLog::where('type', 'disk_cleaner')->where('action', 'schedule_updated')->exists())->toBeTrue();
});

it('rejects an invalid frequency or a non-safe category', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/disk-cleaner/schedule', ['enabled' => true, 'frequency' => 'yearly', 'categories' => ['service_logs']])
        ->assertJsonValidationErrors('frequency');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/disk-cleaner/schedule', ['enabled' => true, 'frequency' => 'daily', 'categories' => ['nope']])
        ->assertJsonValidationErrors('categories.0');
});

it('deletes the schedule', function () {
    DiskCleanerSchedule::create(['enabled' => true, 'frequency' => 'daily', 'categories' => ['service_logs']]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson('/api/disk-cleaner/schedule')
        ->assertNoContent();

    expect(DiskCleanerSchedule::query()->count())->toBe(0);
});

it('lists run history', function () {
    DiskCleanerRun::create(['trigger' => 'manual', 'categories' => ['tmp'], 'freed_total' => 2048, 'status' => 'completed']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/disk-cleaner/runs')
        ->assertOk()
        ->assertJsonPath('runs.0.trigger', 'manual')
        ->assertJsonPath('runs.0.freed_total', 2048)
        ->assertJsonStructure(['runs', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
});

it('denies a viewer without manage from changing the schedule', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'disk_cleaner', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/disk-cleaner/schedule', ['enabled' => true, 'frequency' => 'daily', 'categories' => ['service_logs']])
        ->assertForbidden();
});

// ---- scheduler command ----

it('runs the scheduled cleaner when enabled, due and over threshold', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 10:30:00'));
    fakeDiskAt(85);
    DiskCleanerSchedule::create([
        'enabled' => true, 'frequency' => 'hourly', 'categories' => ['service_logs'],
        'threshold_percent' => 80, 'last_run_at' => null,
    ]);

    $this->artisan('disk-cleaner:run')->assertExitCode(0);

    expect(DiskCleanerRun::where('trigger', 'scheduled')->count())->toBe(1);
    expect(DiskCleanerSchedule::first()->last_run_at)->not->toBeNull();
    Process::assertRan(fn ($p) => $p->command[0] === 'truncate');
});

it('skips when disk is below the threshold (and does not mark as run)', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 10:30:00'));
    fakeDiskAt(50);
    DiskCleanerSchedule::create([
        'enabled' => true, 'frequency' => 'hourly', 'categories' => ['service_logs'],
        'threshold_percent' => 80, 'last_run_at' => null,
    ]);

    $this->artisan('disk-cleaner:run')->assertExitCode(0);

    expect(DiskCleanerRun::query()->count())->toBe(0);
    expect(DiskCleanerSchedule::first()->last_run_at)->toBeNull();
});

it('does nothing when disabled', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 10:30:00'));
    fakeDiskAt(95);
    DiskCleanerSchedule::create([
        'enabled' => false, 'frequency' => 'hourly', 'categories' => ['service_logs'], 'last_run_at' => null,
    ]);

    $this->artisan('disk-cleaner:run')->assertExitCode(0);

    expect(DiskCleanerRun::query()->count())->toBe(0);
});

it('does not run again within the same slot', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 10:30:00'));
    fakeDiskAt(95);
    DiskCleanerSchedule::create([
        'enabled' => true, 'frequency' => 'hourly', 'categories' => ['service_logs'],
        'last_run_at' => Carbon::parse('2026-07-27 10:05:00'), // already ran this 10:00 slot
    ]);

    $this->artisan('disk-cleaner:run')->assertExitCode(0);

    expect(DiskCleanerRun::query()->count())->toBe(0);
});
