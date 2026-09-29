<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\GitDeployer;
use App\Services\Server\Applications\SecretFilePrivacy;
use App\Services\Server\Php\RuntimeOwnership;
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

    app(SecretFilePrivacy::class)->narrow($this->application);

    $env = $this->application->codePath().'/.env';

    // `go-rwx` never widens: it only removes. The group goes too — the site
    // runs as its own user, and on OpenLiteSpeed the web server account is a
    // member of that user's group.
    expect(collect($ran)->contains(['runuser', '-u', 'envuser', '--', 'chmod', 'go-rwx', $env]))->toBeTrue()
        // The site runs as its own user, so the group is already right.
        ->and(collect($ran)->contains(fn (array $c) => ($c[0] ?? '') === 'chown'))->toBeFalse();
});

it('hands the group to the web server first where PHP runs as that', function () {
    // No pool of its own: taking the "other" bit alone would take the site's
    // own configuration away from it.
    $this->application->forceFill(['isolated_at' => null])->save();
    $ran = fakeEnvServer();

    app(SecretFilePrivacy::class)->narrow($this->application->fresh());

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

    app(SecretFilePrivacy::class)->narrow($this->application);

    expect(collect($ran)->contains(fn (array $c) => in_array('chmod', $c, true)))->toBeFalse();
});

it('never fails the caller when it cannot tell whether the file is there', function () {
    // `test` printing to stderr is "no answer", which the environment screen
    // turns into an exception. Here it must only mean "leave it alone".
    $ran = fakeEnvServer(testExit: 1, testStderr: 'sudo: a password is required');

    app(SecretFilePrivacy::class)->narrow($this->application);

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
    // Either mode — which one is the ownership rule's business, tested above;
    // this is about when it runs.
    $chmod = $commands->search(fn (array $c) => ($c[4] ?? '') === 'chmod' && in_array($c[5] ?? '', ['go-rwx', 'o-rwx,g-w'], true));

    expect($seed)->not->toBeFalse()
        ->and($chmod)->not->toBeFalse()
        ->and($chmod)->toBeGreaterThan($seed);
});

it('runs from sites:resync, so sites installed before the fix are repaired', function () {
    $ran = fakeEnvServer();

    $this->artisan('sites:resync')
        ->expectsOutputToContain('Secret files (.env, wp-config.php and the like): narrowed where present')
        ->assertSuccessful();

    expect(collect($ran)->contains(fn (array $c) => ($c[4] ?? '') === 'chmod' && ($c[5] ?? '') === 'go-rwx'
        && str_starts_with((string) ($c[6] ?? ''), '/home/envuser/books/')))->toBeTrue();
});

it('covers the config file each installer writes, not only .env', function () {
    $this->application->forceFill(['site_type' => 'wordpress'])->save();
    $ran = fakeEnvServer();

    app(SecretFilePrivacy::class)->narrow($this->application->fresh());

    $config = rtrim($this->application->fresh()->documentRoot(), '/').'/wp-config.php';

    expect(collect($ran)->contains(['runuser', '-u', 'envuser', '--', 'chmod', 'go-rwx', $config]))->toBeTrue();
});

it('writes secrets 0600 where the site runs as its own user, 0640 where PHP runs as the web server', function () {
    $ownership = app(RuntimeOwnership::class);

    expect($ownership->secretFileMode($this->application))->toBe('0600');

    $this->application->forceFill(['isolated_at' => null])->save();

    expect($ownership->secretFileMode($this->application->fresh()))->toBe('0640');
});

it('covers config files the application writes itself', function (string $type, string $relative) {
    $this->application->forceFill(['site_type' => $type])->save();
    $ran = fakeEnvServer();

    app(SecretFilePrivacy::class)->narrow($this->application->fresh());

    $file = rtrim($this->application->fresh()->codePath(), '/').'/'.$relative;

    expect(collect($ran)->contains(['runuser', '-u', 'envuser', '--', 'chmod', 'go-rwx', $file]))->toBeTrue();
})->with([
    'Joomla' => ['joomla', 'configuration.php'],
    'PrestaShop' => ['prestashop', 'app/config/parameters.php'],
    'Nextcloud' => ['nextcloud', 'config/config.php'],
]);

it('covers Statamic user files, which hold password hashes, at the project root', function () {
    $this->application->forceFill(['site_type' => 'statamic', 'web_root' => '/public'])->save();
    $ran = fakeEnvServer();

    app(SecretFilePrivacy::class)->narrow($this->application->fresh());

    $users = rtrim($this->application->fresh()->codePath(), '/').'/users';

    expect($users)->not->toContain('/public/')
        ->and(collect($ran)->contains(['runuser', '-u', 'envuser', '--', 'find', $users, '-maxdepth', '1', '-type', 'f',
            '-name', '*.yaml', '-exec', 'chmod', 'go-rwx', '{}', '+']))->toBeTrue();
});
