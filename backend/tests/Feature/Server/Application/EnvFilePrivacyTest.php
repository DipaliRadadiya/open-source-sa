<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\EnvFilePrivacy;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Support\Facades\Process;

/*
 * Statamic's create-project, Akaunting, Mautic, PrestaShop and a git deploy's
 * seed all leave `.env` 0644, readable by the web server account until the
 * user saved it or pressed "Fix permissions" (measured on 2026-09-29).
 */

beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true],
        'source' => 'installer', 'verified_at' => now()]);

    $this->su = SystemUser::create(['username' => 'envuser', 'home_path' => '/home/envuser', 'shell' => '/bin/bash', 'sudo' => false]);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Books', 'slug' => 'books', 'domain' => 'books.example.com',
        'site_type' => 'akaunting', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/',
        'status' => 'active', 'isolated_at' => now(),
    ]);
});

/**
 * @return ArrayObject<int, array<int, string>>
 */
function fakeEnvServer(int $testExit = 0, string $testStderr = ''): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran, $testExit, $testStderr) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran->append($args);

        $isTest = in_array('test', $args, true) && in_array('-f', $args, true);

        return $isTest
            ? Process::result(errorOutput: $testStderr, exitCode: $testExit)
            : Process::result();
    });

    return $ran;
}

it('takes world access off the file, as the site user, and adds none', function () {
    $ran = fakeEnvServer();

    app(EnvFilePrivacy::class)->narrow($this->application);

    $env = $this->application->codePath().'/.env';

    // `o-rwx,g-w` never widens: a file the user made 0600 stays 0600.
    expect(collect($ran)->contains(['runuser', '-u', 'envuser', '--', 'chmod', 'o-rwx,g-w', $env]))->toBeTrue()
        // The site runs as its own user, so the group is already right.
        ->and(collect($ran)->contains(fn (array $c) => ($c[0] ?? '') === 'chown'))->toBeFalse();
});

it('hands the group to the web server first where PHP runs as that', function () {
    // No pool of its own: taking the "other" bit alone would take the site's
    // own configuration away from it.
    $this->application->forceFill(['isolated_at' => null])->save();
    $ran = fakeEnvServer();

    app(EnvFilePrivacy::class)->narrow($this->application->fresh());

    $commands = collect($ran)->values();
    $env = $this->application->codePath().'/.env';
    $chown = $commands->search(['chown', '-h', 'envuser:www-data', $env]);
    $chmod = $commands->search(['runuser', '-u', 'envuser', '--', 'chmod', 'o-rwx,g-w', $env]);

    expect($chown)->not->toBeFalse()
        ->and($chmod)->not->toBeFalse()
        ->and($chown)->toBeLessThan($chmod);
});

it('does nothing when there is no .env', function () {
    $ran = fakeEnvServer(testExit: 1);

    app(EnvFilePrivacy::class)->narrow($this->application);

    expect(collect($ran)->contains(fn (array $c) => in_array('chmod', $c, true)))->toBeFalse();
});

it('never fails the caller when it cannot tell whether the file is there', function () {
    // `test` printing to stderr is "no answer", which the environment screen
    // turns into an exception. Here it must only mean "leave it alone".
    $ran = fakeEnvServer(testExit: 1, testStderr: 'sudo: a password is required');

    app(EnvFilePrivacy::class)->narrow($this->application);

    expect(collect($ran)->contains(fn (array $c) => in_array('chmod', $c, true)))->toBeFalse();
});

it('narrows a git deploy seed right after copying it', function () {
    $app = Application::create([
        'system_user_id' => $this->su->id, 'name' => 'Api', 'domain' => 'api.example.com',
        'site_type' => 'git', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/',
        'status' => 'pending', 'repository_url' => 'https://github.com/octocat/hello.git', 'branch' => 'main',
        'isolated_at' => now(),
    ]);
    $ran = fakeEnvServer();

    try {
        app(GitDeployer::class)->deploy($app->load('systemUser'), $app->codePath());
    } catch (ProvisioningFailedException) {
        // The faked curl at `verify` has no status code; irrelevant here.
    }

    $commands = collect($ran)->values();
    $seed = $commands->search(fn (array $c) => ($c[0] ?? '') === 'runuser' && str_contains((string) ($c[6] ?? ''), '.env.example'));
    $chmod = $commands->search(fn (array $c) => ($c[4] ?? '') === 'chmod' && ($c[5] ?? '') === 'o-rwx,g-w');

    expect($seed)->not->toBeFalse()
        ->and($chmod)->not->toBeFalse()
        ->and($chmod)->toBeGreaterThan($seed);
});

it('runs from sites:resync, so sites installed before the fix are repaired', function () {
    $ran = fakeEnvServer();

    $this->artisan('sites:resync')
        ->expectsOutputToContain('Environment files: world access removed where present')
        ->assertSuccessful();

    expect(collect($ran)->contains(fn (array $c) => ($c[4] ?? '') === 'chmod' && ($c[5] ?? '') === 'o-rwx,g-w'
        && str_starts_with((string) ($c[6] ?? ''), '/home/envuser/books/')))->toBeTrue();
});
