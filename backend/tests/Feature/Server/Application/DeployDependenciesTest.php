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

/*
| A composer project must have its dependencies on disk before anything is
| asked to serve it.
|
| Without this the failure is silent by construction: the checkout is clean,
| the restarts are clean, and the site then answers every request with a fatal
| on the missing `vendor/autoload.php`. The panel's only verdict was "curl
| returned HTTP 500" — correct, and missing the one fact needed to fix it.
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-deps-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'depsuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
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
    ]);
});

/**
 * Fake a checkout: `cat composer.json` answers with $manifest (or fails when
 * null), and `test -f vendor/autoload.php` answers with $vendorExists.
 */
function fakeComposerCheckout(?string $manifest, bool $vendorExists): void
{
    Process::fake(function ($process) use ($manifest, $vendorExists) {
        $command = $process->command;

        if (($command[0] ?? null) === 'cat' && str_contains((string) ($command[1] ?? ''), 'composer.json')) {
            return $manifest === null
                ? Process::result(errorOutput: 'No such file or directory', exitCode: 1)
                : Process::result(output: $manifest);
        }

        if (($command[0] ?? null) === 'test' && str_contains((string) ($command[2] ?? ''), 'vendor/autoload.php')) {
            return Process::result(exitCode: $vendorExists ? 0 : 1);
        }

        // The verify step reads the status code off stdout; an empty answer
        // reads as "connected and returned 0", which fails the deploy.
        if (($command[0] ?? null) === 'curl') {
            return Process::result(output: '200');
        }

        return Process::result(exitCode: 0);
    });
}

function runDependencyDeploy(): void
{
    (new DeployApplication(test()->application->id, test()->admin->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

it('fails with a nameable reason when the dependencies were never installed', function () {
    fakeComposerCheckout('{"require":{"php":"^8.2","laravel/framework":"^13.0"}}', vendorExists: false);

    runDependencyDeploy();

    $application = $this->application->fresh();

    expect($application->failed_step)->toBe('dependencies')
        ->and($application->failed_reason)->toBe('composer_dependencies_missing');
});

it('says so before the site is curled, not after', function () {
    fakeComposerCheckout('{"require":{"laravel/framework":"^13.0"}}', vendorExists: false);

    runDependencyDeploy();

    // Both orders end in a failed deploy. Only this one says why — a 500 from
    // the verify names the symptom and nothing else.
    Process::assertNotRan(fn ($process) => ($process->command[0] ?? null) === 'curl');
});

it('passes when the dependencies are there', function () {
    fakeComposerCheckout('{"require":{"laravel/framework":"^13.0"}}', vendorExists: true);

    runDependencyDeploy();

    expect($this->application->fresh()->failed_reason)->toBeNull();
});

it('leaves a repository with no composer.json alone', function () {
    fakeComposerCheckout(null, vendorExists: false);

    runDependencyDeploy();

    expect($this->application->fresh()->failed_reason)->toBeNull();
});

it('leaves a manifest that only pins dev tooling alone', function (string $manifest) {
    // A repository carrying a composer.json only to pin a linter has no
    // runtime dependencies, serves perfectly well with no `vendor/`, and
    // deploys fine today. Failing it would be this check causing the very
    // outage it exists to describe.
    fakeComposerCheckout($manifest, vendorExists: false);

    runDependencyDeploy();

    expect($this->application->fresh()->failed_reason)->toBeNull();
})->with([
    'dev only' => '{"require-dev":{"laravel/pint":"^1.0"}}',
    // Platform entries are constraints on the interpreter, not packages that
    // land in `vendor/`.
    'platform only' => '{"require":{"php":"^8.2","ext-mbstring":"*"}}',
    'empty require' => '{"require":{}}',
    // Unparseable is not evidence that anything is missing, and this check's
    // licence to fail a deploy rests on being certain.
    'malformed' => '{"require": broken',
]);
