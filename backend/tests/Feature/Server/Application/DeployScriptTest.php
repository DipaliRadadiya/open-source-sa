<?php

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-script-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'scriptuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'App',
        'slug' => 'app',
        'domain' => 'app.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
        'repository' => 'acme/app',
        'repository_url' => 'https://github.com/acme/app.git',
        'branch' => 'develop',
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
});

function deployNow(): void
{
    (new DeployApplication(test()->application->id, test()->admin->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

/** The shell command the script ran inside, or null if none ran. */
function scriptCommand(): ?string
{
    $found = null;

    Process::assertRan(function ($process) use (&$found) {
        $command = $process->command;

        // `set -e` alone is no longer enough to identify it: seeding the .env
        // from the repository's .env.example is also a `set -e` script run
        // through runuser, and it runs first. The `cd` into the checkout is
        // what makes this one the deploy script.
        if (in_array('runuser', $command, true)
            && str_contains(end($command), 'set -e')
            && str_contains(end($command), "\ncd ")) {
            $found = end($command);

            return true;
        }

        return false;
    });

    return $found;
}

it('runs the deploy script as the site user, never as root', function () {
    $this->application->update(['deploy_script' => "composer install --no-dev\nphp artisan migrate --force"]);

    deployNow();

    // `application:manage` must not be a route to running commands as root.
    // The script is whatever the user typed, so the privilege drop is the
    // control that matters here — not a denylist of characters.
    Process::assertRan(fn ($process) => in_array('runuser', $process->command, true)
        && in_array('-u', $process->command, true)
        && in_array('scriptuser', $process->command, true));

    expect(scriptCommand())->toContain('php artisan migrate --force');
});

it('stops at the first failing line', function () {
    $this->application->update(['deploy_script' => "composer install\nphp artisan migrate --force"]);

    deployNow();

    // Without `set -e` a failed composer install is followed cheerfully by the
    // migration, and the deploy reports success on a half-updated site — worse
    // than the failure it hid.
    expect(scriptCommand())->toStartWith('set -e');
});

it('substitutes the placeholders', function () {
    $this->application->update(['deploy_script' => 'echo {path} {branch} {domain}']);

    deployNow();

    $command = scriptCommand();

    expect($command)->toContain($this->home.'/app/public_html')
        ->toContain('develop')
        ->toContain('app.example.com')
        ->not->toContain('{path}');
});

it('falls back to the old build command when no script is written', function () {
    // An application configured before the Deployment screen existed keeps
    // deploying exactly as it did — silently changing what runs on someone's
    // production site is not an upgrade.
    $this->application->update(['deploy_script' => null, 'build_command' => 'npm run build']);

    expect(app(GitDeployer::class)->script($this->application))->toBe('npm run build');

    deployNow();

    expect(scriptCommand())->toContain('npm run build');
});

it('prefers the script once one is written', function () {
    $this->application->update([
        'build_command' => 'npm run build',
        'deploy_script' => 'composer install',
    ]);

    expect(app(GitDeployer::class)->script($this->application))->toBe('composer install');
});

it('runs nothing extra when neither is set', function () {
    $this->application->update(['deploy_script' => null, 'build_command' => null]);

    deployNow();

    // "Nothing extra" means nothing of the *user's*. Seeding the .env from the
    // repository's own .env.example runs on every deploy by design, and is
    // also a runuser script — so the test for the absence of a deploy script
    // has to name the deploy script rather than the mechanism it shares.
    Process::assertNotRan(fn ($process) => in_array('runuser', $process->command, true)
        && str_contains((string) end($process->command), "\ncd "));
});

it('saves a script and reports what will run', function () {
    $response = $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/deployment-settings", [
            'deploy_script' => "composer install --no-dev\nphp artisan migrate --force",
            'branch' => 'main',
        ])
        ->assertOk();

    expect($response->json('settings.deploy_script'))->toContain('artisan migrate')
        ->and($response->json('settings.deploy_script_customised'))->toBeTrue()
        ->and($response->json('settings.branch'))->toBe('main')
        // Sent rather than hardcoded in the frontend, like the cron presets.
        ->and($response->json('settings.placeholders'))->toContain('{path}');

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'application',
        'action' => 'deploy_script_updated',
    ]);
});

it('strips carriage returns from a script pasted on Windows', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/deployment-settings", [
            'deploy_script' => "composer install\r\nphp artisan migrate\r\n",
        ])
        ->assertOk();

    // `sh` reads the \r as part of the command, producing "command not found:
    // composer\r" — an error that is impossible to see in a terminal.
    expect($this->application->fresh()->deploy_script)->not->toContain("\r");
});

it('refuses a branch name that is not one', function (string $branch) {
    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/deployment-settings", ['branch' => $branch])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch');
})->with(['main; rm -rf /', 'feature branch', '$(whoami)', 'main`id`']);

it('offers a starting script suited to the runtime', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/deployments")
        ->assertOk();

    // A starting point, not a policy — it is the user's file the moment they
    // open the screen.
    expect($response->json('settings.default_deploy_script'))->toContain('git pull')
        ->and($response->json('settings.deploy_script_customised'))->toBeFalse();
});

it('needs manage to change the script', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_deployment');

    $this->actingAs($viewer)
        ->putJson("/api/applications/{$this->application->id}/deployment-settings", [
            'deploy_script' => 'rm -rf /',
        ])
        ->assertForbidden();
});
