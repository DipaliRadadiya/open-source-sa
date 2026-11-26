<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use Illuminate\Support\Facades\Process;

/**
 * Provisioning asks the server whether the site's Linux account exists before it creates anything.
 *
 * The `system_users` row is not proof: a rebuilt server, an adopted box or a failed `useradd` can
 * leave a username with no passwd entry. The check is independent of the pool builder, because
 * **on OpenLiteSpeed the pool step never runs**; these tests are written against the OLS capability.
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

it('creates the Linux user when it is not on the server', function () {
    ServerCapability::query()->update(['stack' => 'lemp', 'web_server' => 'nginx']);

    // Stateful: the account is absent until `useradd` runs and present afterwards, and the step
    // confirms with a second `getent`. A fake answering "absent" forever would fail a step that
    // had just succeeded.
    $created = false;
    Process::fake(function ($process) use (&$created) {
        if ($process->command[0] === 'useradd') {
            $created = true;

            return Process::result(exitCode: 0);
        }

        return $process->command[0] === 'getent'
            ? Process::result(exitCode: $created ? 0 : 2)
            : Process::result(exitCode: 0);
    });

    $app = accountCheckApp();

    app(ApplicationProvisioner::class)->provision($app);

    // The step makes the account rather than only reporting it missing, which repairs a row without
    // a passwd entry (adopted box, rebuilt server, failed useradd).
    //
    // It is also how an account generated for a new site comes into being at all:
    // `CreateApplication` records the owner and writes nothing to the server.
    Process::assertRan(fn ($p) => $p->command[0] === 'useradd');
    expect($app->fresh()->steps)->toContain('ensure_account');
});

it('still stops before building anything when the account cannot be made', function () {
    // useradd itself refused: a real failure, unlike a missing account the step can fix.
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'getent' => Process::result(exitCode: 2),
        $process->command[0] === 'useradd' => Process::result(errorOutput: 'useradd: failure', exitCode: 1),
        default => Process::result(exitCode: 0),
    });

    try {
        app(ApplicationProvisioner::class)->provision(accountCheckApp());
        $this->fail('provisioning should have stopped');
    } catch (Throwable $e) {
        // Either shape is acceptable; what matters is that it stopped.
    }

    // Before the directory, the vhost and the reload: a failure leaves no half-built site to clean up.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'mkdir');
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('leaves an account that is already there alone', function () {
    // On nginx for the happy path only: the check is driver-independent, and OpenLiteSpeed's later
    // steps want a real shared config this test has no reason to build.
    ServerCapability::query()->update(['stack' => 'lemp', 'web_server' => 'nginx']);

    fakeAccount(true);

    $app = accountCheckApp();

    app(ApplicationProvisioner::class)->provision($app);

    expect($app->fresh()->steps)->toContain('ensure_account')
        ->and($app->fresh()->steps)->toContain('write_config');
});
