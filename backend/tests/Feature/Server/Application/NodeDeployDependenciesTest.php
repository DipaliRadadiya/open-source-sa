<?php

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use App\Services\Server\Applications\ProcessSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * RC-2 (frontend QA, 9 Oct): a Node application deployed from git with no
 * deploy script never had `npm ci` run, so its first start died on
 * MODULE_NOT_FOUND — the step failed with no reason, and the application
 * stayed `active` while answering 502.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $systemUser = SystemUser::create(['username' => 'nodeuser', 'home_path' => '/home/nodeuser', 'shell' => '/bin/bash', 'sudo' => false]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id, 'name' => 'Api', 'slug' => 'api', 'domain' => 'api.example.com',
        'site_type' => 'git', 'serving_profile' => 'node', 'node_version' => '22', 'app_port' => 3005,
        'start_command' => 'node server.js', 'web_root' => '/', 'status' => 'active',
        'repository' => 'acme/api', 'repository_url' => 'https://github.com/acme/api.git', 'branch' => 'main',
    ]);

    $this->scripts = new ArrayObject;
});

/** A checkout with this package.json, these lockfiles, node_modules present or not. */
function fakeNodeCheckout(?string $manifest, array $lockfiles = [], bool $modules = false): void
{
    $scripts = test()->scripts;

    Process::fake(function ($process) use ($manifest, $lockfiles, $modules, $scripts) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $last = (string) end($args);

        if (($args[0] ?? '') === 'cat' && str_ends_with($last, '/package.json')) {
            return $manifest === null ? Process::result(exitCode: 1, errorOutput: 'No such file') : Process::result(output: $manifest);
        }
        if (($args[0] ?? '') === 'test' && str_ends_with($last, '/node_modules')) {
            return Process::result(exitCode: $modules ? 0 : 1);
        }
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: in_array(basename($last), $lockfiles, true) ? 0 : 1);
        }
        if (in_array('sh', $args, true) && ($i = array_search('-c', $args, true)) !== false) {
            $scripts[] = $args[$i + 1];
        }
        if (($args[0] ?? '') === 'curl') {
            return Process::result(output: '200');
        }

        return Process::result();
    });
}

function deployNode(?DeploymentTrigger $trigger = null): void
{
    $deployment = $trigger === null ? null : Deployment::create([
        'application_id' => test()->application->id, 'trigger' => $trigger, 'status' => DeploymentStatus::Queued, 'branch' => 'main',
    ]);

    (new DeployApplication(test()->application->id, test()->admin->id, $deployment?->id))->handle(
        app(GitDeployer::class), app(ApplicationProvisioner::class), app(ActivityLogger::class), app(DeploymentRecorder::class),
    );
}

function ranInstall(string $needle): bool
{
    foreach (test()->scripts as $script) {
        if (str_contains($script, $needle)) {
            return true;
        }
    }

    return false;
}

$withDeps = '{"name":"api","dependencies":{"express":"^5.0.0"}}';

it('installs the packages when nothing else would', function (array $lockfiles, ?string $manager, string $expected) use ($withDeps) {
    $this->application->update(['package_manager' => $manager]);
    fakeNodeCheckout($withDeps, $lockfiles);

    deployNode();

    expect(ranInstall($expected))->toBeTrue();
})->with([
    'npm lockfile' => [['package-lock.json'], null, 'npm ci'],
    'no lockfile' => [[], null, 'npm install'],
    'pnpm lockfile' => [['pnpm-lock.yaml'], null, 'pnpm install --frozen-lockfile'],
    'yarn lockfile' => [['yarn.lock'], null, 'yarn install --frozen-lockfile'],
    'bun lockfile' => [['bun.lock'], null, 'bun install --frozen-lockfile'],
    'manager on record wins' => [['package-lock.json'], 'pnpm', 'pnpm install --frozen-lockfile'],
]);

it('runs nothing when the packages are already there, or there are none', function (?string $manifest, bool $modules) {
    fakeNodeCheckout($manifest, ['package-lock.json'], $modules);

    deployNode();

    expect(ranInstall('npm ci'))->toBeFalse()->and(ranInstall('npm install'))->toBeFalse();
})->with([
    'node_modules present' => ['{"dependencies":{"express":"^5.0.0"}}', true],
    'no dependencies' => ['{"name":"api"}', false],
    'empty dependencies' => ['{"name":"api","dependencies":{}}', false],
    'no package.json' => [null, false],
]);

it('never runs npm for a PHP application with a package.json', function () use ($withDeps) {
    $this->application->update(['serving_profile' => 'php', 'php_version' => '8.4']);
    fakeNodeCheckout($withDeps, ['package-lock.json']);

    deployNode();

    expect(ranInstall('npm ci'))->toBeFalse()->and(ranInstall('npm install'))->toBeFalse();
});

it('leaves a script the user wrote alone', function () use ($withDeps) {
    $this->application->update(['deploy_script' => "cd {path}\necho mine\n"]);
    fakeNodeCheckout($withDeps, ['package-lock.json']);

    deployNode();

    expect(ranInstall('echo mine'))->toBeTrue()->and(ranInstall('npm ci'))->toBeFalse();
});

it('marks a site whose first deploy failed as failed, not active', function () {
    $this->mock(GitDeployer::class)->shouldReceive('deploy')->andThrow(new ProvisioningFailedException('start_app', 'ref-rc2', 'process_exited'));

    deployNode(DeploymentTrigger::Initial);

    $application = $this->application->fresh();
    expect($application->status->value)->toBe('failed')
        ->and($application->failed_step)->toBe('start_app')
        ->and($application->failed_reason)->toBe('process_exited');
});

it('keeps a live site active when a later deploy fails', function () {
    $this->mock(GitDeployer::class)->shouldReceive('deploy')->andThrow(new ProvisioningFailedException('start_app', 'ref-rc2', 'process_exited'));

    deployNode(DeploymentTrigger::Manual);

    expect($this->application->fresh()->status->value)->toBe('active');
});

it('names why a process did not start', function () {
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return in_array('systemctl', $args, true) && in_array('restart', $args, true)
            ? Process::result(exitCode: 1, errorOutput: 'Job failed')
            : Process::result();
    });

    try {
        app(ProcessSupervisor::class)->apply($this->application, '/home/nodeuser/api/public_html', start: true);
        $this->fail('expected the start to fail');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('start_app')->and($e->reason)->toBe('process_exited');
    }

    expect(__('application.failure_reason.process_exited'))->not->toBe('application.failure_reason.process_exited');
});
