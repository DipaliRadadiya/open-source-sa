<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * A panel-installable service whose unit is absent must still be listed: its install is
 * either running or failed, and the user asked for it.
 *
 * systemctl is faked locally, not via ServiceTest's `fakeUnits()`: Pest helpers exist only
 * once their file is loaded, which would make this file pass or fail by run order. Every unit
 * not named in the array answers not-found.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->phpDir = sys_get_temp_dir().'/sv-oss-php-'.uniqid();
    File::ensureDirectoryExists($this->phpDir);
    config(['server.php_dir' => $this->phpDir]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

/**
 * @param  array<string, array{load: string, active: string, file: string}>  $units
 */
function fakeUnits(array $units): void
{
    Process::fake(function ($process) use ($units) {
        if (($process->command[1] ?? null) === 'show') {
            return Process::result(output: systemctlShowOutput($process->command, $units));
        }

        return Process::result(exitCode: 0);
    });
}

/** @return array<string, mixed>|null */
function serviceRow(string $key): ?array
{
    $services = test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/services')
        ->assertOk()
        ->json('services');

    foreach ($services as $service) {
        if ($service['key'] === $key) {
            return $service;
        }
    }

    return null;
}

it('shows an engine that is still installing, with nothing to press', function () {
    fakeUnits([]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mongodb', 'status' => InstallStatus::Installing, 'started_at' => now(),
    ]);

    $row = serviceRow('mongodb');

    expect($row)->not->toBeNull()
        ->and($row['state'])->toBe('installing')
        // Systemd's own word for "not running", so the badge renders it; `state` holds the reason.
        ->and($row['status'])->toBe('inactive')
        ->and($row['retryable'])->toBeFalse()
        // Inert on purpose: there is no unit to start.
        ->and($row['actions'])->toBe([])
        ->and($row['usage'])->toBeNull()
        ->and($row['testable'])->toBeFalse();
});

it('shows a failed engine install with its reason, and marks it retryable', function () {
    fakeUnits([]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mongodb',
        'status' => InstallStatus::Failed, 'started_at' => now(), 'reason' => 'apt_failed',
    ]);

    $row = serviceRow('mongodb');

    expect($row['state'])->toBe('install_failed')
        // `failed`, not `install_failed`: the badge only knows three words; `state` keeps the detail.
        ->and($row['status'])->toBe('failed')
        ->and($row['retryable'])->toBeTrue()
        ->and($row['install_reason'])->toBe('apt_failed')
        // Prose, not the raw code; the same sentence the setup card shows.
        ->and($row['install_message'])->not->toBeNull()
        ->and($row['install_message'])->not->toContain('runtime.');
});

// The frontend types `status` as a non-nullable string; a null fails the zod parse and drops
// the whole response.
it('never returns a null status, whatever state the row is in', function () {
    fakeUnits(['nginx' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mysql', 'status' => InstallStatus::Installing, 'started_at' => now(),
    ]);

    $services = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/services')->assertOk()->json('services');

    expect($services)->not->toBeEmpty();

    foreach ($services as $service) {
        expect($service['status'])->toBeString("{$service['key']} has a null status")
            ->and($service['state'])->toBeString();
    }
});

it('gives an installed service the installed state and no install fields', function () {
    fakeUnits(['nginx' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);

    $row = serviceRow('nginx');

    expect($row['state'])->toBe('installed')
        ->and($row['status'])->toBe('active')
        ->and($row['install_reason'])->toBeNull()
        ->and($row['retryable'])->toBeFalse();
});

// The unit wins over the tracker row: a surviving row must not add a ghost entry.
it('shows one row, as an installed service, when the unit exists', function () {
    fakeUnits(['mysql' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mysql', 'status' => InstallStatus::Ready, 'started_at' => now(),
    ]);

    $services = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/services')->assertOk()->json('services');

    $mysql = array_values(array_filter($services, fn ($s) => $s['key'] === 'mysql'));

    expect($mysql)->toHaveCount(1)
        ->and($mysql[0]['state'])->toBe('installed');
});

// `ready` with no unit should be unreachable (succeed() deletes the row); this pins the guard.
// The test above cannot cover it: with the unit present describe() returns before the pending path.
it('ignores a ready row left behind with no unit, rather than inventing a service', function () {
    fakeUnits([]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mysql', 'status' => InstallStatus::Ready, 'started_at' => now(),
    ]);

    expect(serviceRow('mysql'))->toBeNull();
});

it('leaves a service nobody has tried to install absent', function () {
    fakeUnits([]);

    // Redis has no installer, so no tracker row can exist for it. Absent stays absent.
    expect(serviceRow('redis'))->toBeNull()
        ->and(serviceRow('mongodb'))->toBeNull();
});

// PHP catalog entries are generated, not configured, so a version that never finished
// installing has no unit and no config entry. `phpFpmServices()` also builds rows from the tracker.
describe('PHP versions', function () {

    it('shows a version that is still installing', function () {
        fakeUnits([]);

        RuntimeInstall::create([
            'runtime' => 'php', 'version' => '8.3',
            'status' => InstallStatus::Installing, 'started_at' => now(),
        ]);

        $row = serviceRow('php8.3-fpm');

        expect($row)->not->toBeNull()
            ->and($row['label'])->toBe('PHP 8.3 FPM')
            ->and($row['state'])->toBe('installing')
            ->and($row['status'])->toBe('inactive')
            ->and($row['actions'])->toBe([]);
    });

    it('shows a version whose install failed, as failed and retryable', function () {
        fakeUnits([]);

        RuntimeInstall::create([
            'runtime' => 'php', 'version' => '8.3',
            'status' => InstallStatus::Failed, 'started_at' => now(), 'reason' => 'apt_failed',
        ]);

        $row = serviceRow('php8.3-fpm');

        expect($row['state'])->toBe('install_failed')
            ->and($row['status'])->toBe('failed')
            ->and($row['retryable'])->toBeTrue();
    });

    // `InstallTracker::versions()` filters out rows with an extension set; pinned here.
    it('does not invent a version row for an extension install', function () {
        fakeUnits([]);

        RuntimeInstall::create([
            'runtime' => 'php', 'version' => '8.3', 'extension' => 'redis',
            'status' => InstallStatus::Installing, 'started_at' => now(),
        ]);

        expect(serviceRow('php8.3-fpm'))->toBeNull();
    });
});

// The mapping is config-driven, so fail2ban gets the same treatment for free.
it('covers fail2ban too, because the mapping is config not a match', function () {
    fakeUnits([]);

    RuntimeInstall::create([
        'runtime' => 'fail2ban', 'version' => 'latest',
        'status' => InstallStatus::Failed, 'started_at' => now(), 'reason' => 'apt_failed',
    ]);

    expect(serviceRow('fail2ban')['state'])->toBe('install_failed');
});
