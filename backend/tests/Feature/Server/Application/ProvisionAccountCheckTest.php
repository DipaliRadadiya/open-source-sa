<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use Illuminate\Support\Facades\Process;

/**
 * Provisioning asks the server whether the site's Linux account exists before
 * it creates anything.
 *
 * The `system_users` row is not proof: it records what the panel created, and
 * a server rebuilt under a surviving database, an adopted box, or a `useradd`
 * that failed somewhere the row outlived all leave a username with no passwd
 * entry. `PoolManager` learned this and asked `getent` before writing a pool —
 * but the check lived *inside* the pool builder, and pools exist on the FPM
 * stack alone. **On OpenLiteSpeed the pool step never runs**, so nothing asked:
 * the directory was created, `chown` exited 1 several steps later, and the user
 * was given a reference number in place of "that account is not on this
 * server".
 *
 * Written against the OLS capability for that reason.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'ols', 'web_server' => 'openlitespeed',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'ghost', 'home_path' => '/home/ghost',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function accountCheckApp(): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->su->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com',
        'site_type' => 'php', 'serving_profile' => 'php',
        'web_root' => '/', 'status' => 'provisioning',
    ]);
}

/**
 * @param  bool  $exists  what `getent passwd` says about the account
 */
function fakeAccount(bool $exists): void
{
    Process::fake(fn ($process) => $process->command[0] === 'getent'
        ? Process::result(exitCode: $exists ? 0 : 2)
        : Process::result(exitCode: 0));
}

it('stops at the account check when the Linux user is not on the server', function () {
    fakeAccount(false);

    $app = accountCheckApp();

    // Named, so the failure says which part broke rather than surfacing as a
    // chown several steps later — `ProvisionApplication` copies this onto the
    // application as `failed_step`.
    try {
        app(ApplicationProvisioner::class)->provision($app);
        $this->fail('provisioning should have stopped at the account check');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('check_account');
    }
});

it('creates nothing at all when the account is missing', function () {
    fakeAccount(false);

    try {
        app(ApplicationProvisioner::class)->provision(accountCheckApp());
    } catch (ProvisioningFailedException) {
        // Expected; this test is about what did *not* run.
    }

    // Before the directory, before the vhost, before the reload — the point of
    // checking first is that a failure leaves no half-built site to clean up.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'mkdir');
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('carries on when the account is there', function () {
    // On nginx for the happy path only: the check itself is driver-independent
    // — that is the whole point of moving it out of the pool builder — and
    // OpenLiteSpeed's later steps want a real shared config this test has no
    // reason to build.
    ServerCapability::query()->update(['stack' => 'lemp', 'web_server' => 'nginx']);

    fakeAccount(true);

    $app = accountCheckApp();

    app(ApplicationProvisioner::class)->provision($app);

    expect($app->fresh()->steps)->toContain('check_account')
        ->and($app->fresh()->steps)->toContain('write_config');
});
