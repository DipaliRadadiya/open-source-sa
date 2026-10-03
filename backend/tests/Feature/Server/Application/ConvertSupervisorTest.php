<?php

use App\Actions\Server\Application\ConvertSupervisor;
use App\Enums\SupervisorMode;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use Illuminate\Support\Facades\Process;

/**
 * Moving an adopted application off the old panel's daemon onto a unit.
 *
 * Adoption cannot do this — taking a server over must not restart a customer's
 * site — so it is a separate, explicit choice. It restarts the application, and
 * the only thing that makes that acceptable is that it puts everything back if
 * the new supervisor cannot serve a page.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'mern', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'appuser', 'home_path' => '/home/appuser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function convertibleApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'API',
        'domain' => 'api.test',
        'site_type' => 'git',
        'serving_profile' => 'node',
        'status' => 'active',
        'web_root' => '/',
        'node_version' => '20.11.0',
        'app_port' => 3000,
        'start_command' => 'node server.js',
        'supervisor_mode' => 'pm2',
        'pm2_process_name' => 'legacy-api',
    ], $overrides));
}

/**
 * A server where everything works: PM2 answers, systemd answers, and the
 * application serves a page on its port.
 *
 * @return ArrayObject<int, string>
 */
function convertFake(array $options = []): ArrayObject
{
    $ran = new ArrayObject;
    $readinessCode = $options['readiness'] ?? '200';
    $unitActive = $options['unit_active'] ?? true;

    Process::fake(function ($p) use ($ran, $readinessCode, $unitActive) {
        $args = ($p->command[0] ?? '') === 'sudo' ? array_slice((array) $p->command, 2) : (array) $p->command;
        $line = implode(' ', $args);
        $ran[] = $line;

        if (($args[0] ?? '') === 'systemctl' && ($args[1] ?? '') === 'is-active') {
            return Process::result(exitCode: $unitActive ? 0 : 3, output: $unitActive ? 'active' : 'inactive');
        }

        if (($args[0] ?? '') === 'curl') {
            return Process::result(output: $readinessCode);
        }

        return Process::result(output: '');
    });

    return $ran;
}

it('stops PM2 before starting the unit, so the port is free to bind', function () {
    $application = convertibleApp();

    $ran = convertFake();

    app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test');

    $order = collect($ran)->values();
    $stop = $order->search(fn (string $c) => str_contains($c, 'pm2 stop legacy-api'));
    $start = $order->search(fn (string $c) => str_contains($c, 'systemctl restart sv-app-'));

    expect($stop)->not->toBeFalse()
        ->and($start)->not->toBeFalse()
        ->and($stop)->toBeLessThan($start);
});

it('discards the old definition only after the new one serves a page', function () {
    $application = convertibleApp();

    $ran = convertFake();

    app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test');

    $order = collect($ran)->values();
    $readiness = $order->search(fn (string $c) => str_starts_with($c, 'curl'));
    $delete = $order->search(fn (string $c) => str_contains($c, 'pm2 delete legacy-api'));

    // Until `pm2 delete` runs, the daemon still holds a complete definition of
    // this application — and that definition is the rollback.
    expect($readiness)->not->toBeFalse()
        ->and($delete)->not->toBeFalse()
        ->and($delete)->toBeGreaterThan($readiness);
});

it('records the new mode and forgets the daemon\'s name for it', function () {
    $application = convertibleApp();

    convertFake();

    app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test');

    expect($application->fresh()->supervisor_mode)->toBe(SupervisorMode::Systemd)
        ->and($application->fresh()->pm2_process_name)->toBeNull();
});

it('puts the application back under PM2 when the unit will not serve', function () {
    $application = convertibleApp();

    // The unit starts and stays up; the application answers 502. This is the
    // case `is-active` cannot see and the whole reason readiness is checked.
    $ran = convertFake(['readiness' => '502']);

    expect(fn () => app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test'))
        ->toThrow(ProvisioningFailedException::class);

    expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'pm2 start legacy-api')))->toBeTrue();

    // Nothing was persisted, so the row still describes a working application.
    expect($application->fresh()->supervisor_mode)->toBe(SupervisorMode::Pm2)
        ->and($application->fresh()->pm2_process_name)->toBe('legacy-api');
});

it('never deletes the PM2 definition it might have to roll back to', function () {
    $application = convertibleApp();

    $ran = convertFake(['readiness' => '500']);

    expect(fn () => app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test'))
        ->toThrow(ProvisioningFailedException::class);

    expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'pm2 delete')))->toBeFalse();
});

it('removes the half-built unit before reverting the mode', function () {
    $application = convertibleApp();

    // Readiness, not start, so `apply()` succeeded and did not clean up after
    // itself — the unit is live and only this rollback can take it out.
    //
    // `ProcessSupervisor::remove()` routes on the mode. Reverting first would
    // send the removal to the PM2 driver and leave our unit enabled on disk —
    // starting a second copy of this application at the next boot.
    $ran = convertFake(['readiness' => '503']);

    expect(fn () => app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test'))
        ->toThrow(ProvisioningFailedException::class);

    $order = collect($ran)->values();
    $removed = $order->search(fn (string $c) => str_contains($c, 'rm -f') && str_contains($c, 'sv-app-'));
    $restarted = $order->search(fn (string $c) => str_contains($c, 'pm2 start legacy-api'));

    expect($removed)->not->toBeFalse('the unit was never removed')
        ->and($restarted)->not->toBeFalse()
        ->and($removed)->toBeLessThan($restarted);

    // And it was disabled, so it cannot come back at boot either.
    expect($order->contains(fn (string $c) => str_contains($c, 'systemctl disable sv-app-')))->toBeTrue();
});

it('refuses an application with no entrypoint systemd could execute', function () {
    // The old panel stored `npm run start` and rewrote it on the way to PM2, so
    // many adopted applications have nothing that could go in an ExecStart.
    // Guessing one by reading package.json is how an adopted site fails to
    // come back; the user supplies it.
    $application = convertibleApp(['start_command' => null]);

    Process::fake();

    expect(app(ConvertSupervisor::class)->convertible($application))->toBeFalse();

    expect(fn () => app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test'))
        ->toThrow(ProvisioningFailedException::class);

    Process::assertNothingRan();
});

it('does nothing to an application that is already on a unit', function () {
    $application = convertibleApp(['supervisor_mode' => 'systemd', 'pm2_process_name' => null]);

    Process::fake();

    app(ConvertSupervisor::class)->toSystemd($application, '/home/appuser/api.test');

    Process::assertNothingRan();
});
