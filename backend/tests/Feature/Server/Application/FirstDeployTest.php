<?php

use App\Enums\DeploymentTrigger;
use App\Jobs\DeployApplication;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Creating a site from a repository and ending up with the repository on disk.
 *
 * Three separate defects made that impossible, and each one hid the next:
 *
 *  1. provisioning wrote a placeholder into the document root, and `git clone`
 *     refuses a destination that is not empty — so the first deploy of every
 *     git application failed;
 *  2. nothing dispatched that deploy anyway, so the failure was never even
 *     reached: the site just sat there serving the placeholder;
 *  3. the placeholder was written after `chown -R`, leaving a root-owned file
 *     in the site user's directory that the File Manager could list but never
 *     edit or delete.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'deploy', 'home_path' => '/home/deploy',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function firstDeployApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'repository_url' => 'https://github.com/octocat/hello.git',
        'branch' => 'main',
    ], $overrides));
}

/**
 * Records every command, and lets a test decide what the document root
 * contains when git asks.
 */
function fakeFirstDeploy(string $contents = ''): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran, $contents) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        $ran->append($args);

        if (($args[0] ?? '') === 'find') {
            return Process::result(output: $contents);
        }

        // Not a repository yet: this is a first deploy.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function runFirstDeploy(Application $application): void
{
    (new DeployApplication($application->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

function firstDeployRan(ArrayObject $ran, callable $matches): bool
{
    foreach ($ran as $args) {
        if ($matches($args)) {
            return true;
        }
    }

    return false;
}

it('does not write a placeholder into a git site', function () {
    $app = firstDeployApp();
    $ran = fakeFirstDeploy();

    app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

    // The placeholder is what made `git clone` refuse the directory.
    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'tee'
        && str_contains((string) ($args[1] ?? ''), 'index.php')))->toBeFalse();

    expect($app->fresh()->steps)->not->toContain('placeholder');
});

it('still writes a placeholder for a blank PHP site, before taking ownership', function () {
    $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
    $ran = fakeFirstDeploy();

    app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

    $order = [];

    foreach ($ran as $args) {
        if (in_array($args[0] ?? '', ['tee', 'chown'], true)) {
            $order[] = $args[0];
        }
    }

    // `tee` runs elevated, so a placeholder written after the chown is a
    // root-owned file the site's own user cannot edit or delete.
    expect($order[0] ?? null)->toBe('tee')
        ->and($order[1] ?? null)->toBe('chown');
});

it('uses init, not clone, even when the directory is empty', function () {
    // Deliberately never `git clone`: it used to be the fast path for an
    // empty directory, gated by a separate pre-check, but the check and the
    // clone are two separate commands and anything landing in the directory
    // between them (a second deploy racing this one, a retry) makes `git
    // clone` refuse with "already exists and is not an empty directory" —
    // a real production failure this replaces. `git init` never refuses
    // based on directory contents, so there is no gap left to race.
    $app = firstDeployApp(['status' => 'active']);
    $ran = fakeFirstDeploy(contents: '');

    runFirstDeploy($app);

    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'git' && ($args[1] ?? '') === 'clone'))->toBeFalse()
        ->and(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'git' && ($args[1] ?? '') === 'init'))->toBeTrue();
});

it('fetches instead of cloning when the directory already has files', function () {
    $app = firstDeployApp(['status' => 'active']);

    // A migrated server's existing files, or our own placeholder. `git clone`
    // is a fatal error here, so the deploy has to take the other path.
    $ran = fakeFirstDeploy(contents: "index.php\n.env");

    runFirstDeploy($app);

    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'git' && ($args[1] ?? '') === 'clone'))->toBeFalse()
        ->and(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'git' && ($args[1] ?? '') === 'init'))->toBeTrue()
        ->and(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'git' && in_array('fetch', $args, true)))->toBeTrue()
        // The reset is what makes it equivalent to a clone rather than a merge
        // with whatever happened to be in the directory.
        ->and(firstDeployRan($ran, fn ($args) => in_array('reset', $args, true) && in_array('--hard', $args, true)))->toBeTrue();
});

it('never puts the credential in the stored remote on the fetch path', function () {
    $app = firstDeployApp(['status' => 'active']);
    $ran = fakeFirstDeploy(contents: 'index.php');

    runFirstDeploy($app);

    foreach ($ran as $args) {
        if (in_array('set-url', $args, true)) {
            expect(implode(' ', $args))->not->toContain('@github.com/octocat');
        }
    }

    expect(firstDeployRan($ran, fn ($args) => in_array('set-url', $args, true)))->toBeTrue();
});

it('deploys a git site automatically once it is provisioned', function () {
    Queue::fake();

    $app = firstDeployApp();
    fakeFirstDeploy();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    // "Create a site from this repository" includes fetching the repository.
    // Before this the user got a provisioned site showing a placeholder and no
    // indication that a second, manual step existed.
    Queue::assertPushed(DeployApplication::class);

    $deployment = Deployment::first();

    // Recorded with its own trigger: nobody pressed anything, and a history
    // that claims they did makes the rest of the history untrustworthy.
    expect($deployment)->not->toBeNull()
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Initial);
});

it('does not deploy a site that has no repository', function () {
    Queue::fake();

    $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
    fakeFirstDeploy();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    Queue::assertNotPushed(DeployApplication::class);
    expect(Deployment::count())->toBe(0);
});

it('does not deploy when provisioning failed', function () {
    Queue::fake();

    $app = firstDeployApp();

    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'mkdir'
        ? Process::result(exitCode: 1, errorOutput: 'nope')
        : Process::result(exitCode: 0));

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    // Cloning into a site whose directory was never made would fail second and
    // report a confusing reason for a problem that already had a clear one.
    expect($app->fresh()->status->value)->toBe('failed');
    Queue::assertNotPushed(DeployApplication::class);
});
