<?php

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
| The deploy script's interpreter, and what the panel says when composer is
| the thing that broke.
|
| The bug these were written for: a site set to PHP 8.2 on a box whose default
| `php` is 8.4 had `composer install` resolve against 8.4, which composer then
| records in `vendor/composer/platform_check.php` — required by
| `vendor/autoload.php` on its way in. Every step of the deploy went green and
| the site answered every request with a 500 nobody could trace, because the
| build log showed a clean install of the wrong thing.
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-phpver-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'phpuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'App',
        'slug' => 'app',
        'domain' => 'app.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.2',
        'web_root' => '/',
        'status' => 'active',
        'repository' => 'acme/app',
        'repository_url' => 'https://github.com/acme/app.git',
        'branch' => 'main',
        'deploy_script' => 'composer install --no-dev',
    ]);

    Process::fake(fn ($process) => ($process->command[0] ?? null) === 'curl'
        ? Process::result(output: '200')
        : Process::result(exitCode: 0));

    $this->deployment = Deployment::create([
        'application_id' => $this->application->id,
        'user_id' => $this->admin->id,
        'trigger' => 'manual',
        'status' => 'running',
        'branch' => 'main',
        'started_at' => now(),
    ]);
});

function runPhpVersionDeploy(): void
{
    (new DeployApplication(test()->application->id, test()->admin->id, test()->deployment->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

/** The shell the deploy script ran inside, or null. */
function phpDeployShell(): ?string
{
    $found = null;

    Process::assertRan(function ($process) use (&$found) {
        $command = $process->command;

        if (in_array('runuser', $command, true)
            && str_contains((string) end($command), 'set -e')
            && str_contains((string) end($command), "\ncd ")) {
            $found = end($command);

            return true;
        }

        return false;
    });

    return $found;
}

it('runs the deploy script under the PHP version the site is set to', function () {
    runPhpVersionDeploy();

    // The shim directory, not `dirname('/usr/bin/php8.2')` — every apt PHP
    // lives in `/usr/bin`, so prepending that selects nothing at all.
    expect(phpDeployShell())->toContain('export PATH=')
        ->toContain('/var/lib/panel/php-shims/8.2');
});

it('builds the shim as a directory holding one symlink called php', function () {
    runPhpVersionDeploy();

    // `composer` is started through `#!/usr/bin/env php`, so a file named
    // exactly `php` early on PATH is the only thing that redirects it.
    Process::assertRan(fn ($process) => $process->command === [
        'ln', '-sfn', '/usr/bin/php8.2', '/var/lib/panel/php-shims/8.2/php',
    ]);
});

it('deploys exactly as before when the shim cannot be built', function () {
    // A site whose chosen PHP has since been uninstalled. A dangling symlink
    // named `php` at the front of PATH would break a deploy that works today,
    // which is the one outcome this must never produce.
    Process::fake(function ($process) {
        if ($process->command[0] === 'test' && in_array('-x', $process->command, true)) {
            return Process::result(exitCode: 1);
        }

        if (($process->command[0] ?? null) === 'curl') {
            return Process::result(output: '200');
        }

        return Process::result(exitCode: 0);
    });

    runPhpVersionDeploy();

    expect(phpDeployShell())->not->toContain('php-shims');
    expect($this->application->fresh()->status->value)->toBe('active');
});

it('leaves the interpreter alone when the operator pinned php by PATH', function () {
    // A blank pattern is the operator saying "whatever `php` resolves to".
    config(['server.php_binary_pattern' => '']);

    runPhpVersionDeploy();

    expect(phpDeployShell())->not->toContain('php-shims');
});

it('substitutes {php} with the site\'s own binary', function () {
    $this->application->update(['deploy_script' => '{php} artisan migrate --force']);

    runPhpVersionDeploy();

    expect(phpDeployShell())->toContain('/usr/bin/php8.2 artisan migrate --force')
        ->not->toContain('{php}');
});

it('names composer platform failures instead of leaving a reference', function () {
    // Composer 2.10's own wording, measured rather than remembered.
    Process::fake(function ($process) {
        if (str_contains((string) end($process->command), "\ncd ")) {
            return Process::result(
                output: "Your requirements could not be resolved to an installable set of packages.\n"
                    .'  - Root composer.json requires php ^8.4 but your php version (8.2.28) does not satisfy that requirement.',
                exitCode: 2,
            );
        }

        if (($process->command[0] ?? null) === 'curl') {
            return Process::result(output: '200');
        }

        return Process::result(exitCode: 0);
    });

    runPhpVersionDeploy();

    $application = $this->application->fresh();

    expect($application->failed_step)->toBe('script')
        ->and($application->failed_reason)->toBe('composer_platform');

    // And on the screen somebody actually opens after a failed deploy.
    expect($this->deployment->fresh()->failed_reason)->toBe('composer_platform');
});

it('does not call an ordinary package conflict a platform problem', function () {
    // Measured control: two packages that cannot agree produce none of the
    // platform wording. A wrong reason sends someone to fix something that
    // was never broken.
    Process::fake(function ($process) {
        if (str_contains((string) end($process->command), "\ncd ")) {
            return Process::result(
                output: "Your requirements could not be resolved to an installable set of packages.\n"
                    .'  - monolog/monolog[3.0.0] require psr/log ^3.0 -> found psr/log[1.0.0] but it conflicts with your root composer.json require (^1.0).',
                exitCode: 2,
            );
        }

        if (($process->command[0] ?? null) === 'curl') {
            return Process::result(output: '200');
        }

        return Process::result(exitCode: 0);
    });

    runPhpVersionDeploy();

    expect($this->application->fresh()->failed_reason)->toBeNull();
});

it('clears a stale reason when the next deploy succeeds', function () {
    $this->application->update(['failed_step' => 'script', 'failed_reason' => 'composer_platform']);

    runPhpVersionDeploy();

    $application = $this->application->fresh();

    expect($application->failed_reason)->toBeNull()
        ->and($application->failed_step)->toBeNull();
});
