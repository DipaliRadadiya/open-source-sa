<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * A service whose unit is absent used to be simply missing from this list. For
 * anything the panel can install that reads as "I asked for MySQL and it went
 * nowhere" — the install is either still running or it failed, and both are
 * things the person looking at this page asked for.
 *
 * systemctl is faked locally rather than by borrowing ServiceTest's
 * `fakeUnits()`. Pest helpers only exist once their file has been loaded, so
 * that dependency passes when the directory is run and fails when this file is
 * run alone — an order-dependent green, which is worse than a duplicated
 * closure. Every unit not named in the array answers not-found, which is the
 * state these tests are about.
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
            $unit = $process->command[2] ?? '';
            $s = $units[$unit] ?? ['load' => 'not-found', 'active' => 'inactive', 'file' => 'disabled'];

            return Process::result(output: "LoadState={$s['load']}\nActiveState={$s['active']}\nUnitFileState={$s['file']}\n");
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
        // Systemd's own word for "not running", so the status badge renders it
        // rather than falling through to its unknown-status question mark.
        // `state` is where the reason lives.
        ->and($row['status'])->toBe('inactive')
        ->and($row['retryable'])->toBeFalse()
        // Inert on purpose: there is no unit to start, and a Restart button for
        // something that does not exist is worse than no button.
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
        // `failed`, not `install_failed`: to the person looking at the row it
        // is broken and should be red, and the badge only knows three words.
        // The distinction survives in `state`.
        ->and($row['status'])->toBe('failed')
        ->and($row['retryable'])->toBeTrue()
        ->and($row['install_reason'])->toBe('apt_failed')
        // Prose, not the raw code — and the same sentence the setup card shows,
        // because both read it off the model.
        ->and($row['install_message'])->not->toBeNull()
        ->and($row['install_message'])->not->toContain('runtime.');
});

/*
 * The frontend types `status` as a non-nullable string. A null would fail the
 * zod parse and drop the entire response, so these rows carry a real value —
 * this is the assertion that stops someone "tidying" it to null later.
 */
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

/*
 * The unit winning over the tracker row is what makes the transition work: a
 * successful install deletes its row, but if one survived it must not add a
 * ghost entry beside the real service.
 */
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

/*
 * `ready` with no unit is a state the code should not be able to reach —
 * succeed() deletes the row — so this pins the guard rather than a behaviour
 * anybody sees. It exists because the first version of the test above claimed
 * to cover it and did not: with the unit present, describe() returns before the
 * pending path is ever consulted, so it passed with the guard removed.
 */
it('ignores a ready row left behind with no unit, rather than inventing a service', function () {
    fakeUnits([]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mysql', 'status' => InstallStatus::Ready, 'started_at' => now(),
    ]);

    expect(serviceRow('mysql'))->toBeNull();
});

it('leaves a service nobody has tried to install absent', function () {
    fakeUnits([]);

    // Redis has no installer, so it has no `install` key in the catalog and no
    // tracker row can exist for it. Absent stays absent — this list is still
    // "what is on this box", not a catalogue of everything that could be.
    expect(serviceRow('redis'))->toBeNull()
        ->and(serviceRow('mongodb'))->toBeNull();
});

/*
 * PHP is the one kind of service whose catalog entries are generated rather
 * than configured, so the `install` key that covers everything else cannot be
 * written for it. A version that never finished installing has no unit and no
 * config entry — it could not appear at all, which is exactly when somebody
 * wants to see it. `phpFpmServices()` therefore builds rows from the tracker
 * as well as from what is on disk.
 */
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

    /*
     * `InstallTracker::versions()` filters rows with an extension set, so this
     * passes because of the tracker's design rather than anything this manager
     * remembers to do — worth pinning so a future change there is caught here.
     */
    it('does not invent a version row for an extension install', function () {
        fakeUnits([]);

        RuntimeInstall::create([
            'runtime' => 'php', 'version' => '8.3', 'extension' => 'redis',
            'status' => InstallStatus::Installing, 'started_at' => now(),
        ]);

        expect(serviceRow('php8.3-fpm'))->toBeNull();
    });
});

/*
 * Free consequence of driving this from config rather than a match: fail2ban
 * gets the same treatment without the manager knowing anything about it.
 */
it('covers fail2ban too, because the mapping is config not a match', function () {
    fakeUnits([]);

    RuntimeInstall::create([
        'runtime' => 'fail2ban', 'version' => 'latest',
        'status' => InstallStatus::Failed, 'started_at' => now(), 'reason' => 'apt_failed',
    ]);

    expect(serviceRow('fail2ban')['state'])->toBe('install_failed');
});
