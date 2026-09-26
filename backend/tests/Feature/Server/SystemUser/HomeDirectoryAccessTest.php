<?php

use App\Contracts\WebServerDriver;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Doctor\Checks\HomeAccessCheck;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/**
 * Homes closed to every other local account, open by ACL to the panel and the
 * web server only.
 *
 * Measured on the test servers: with homes at 751 and application files 644,
 * any other site user read PrestaShop's and Joomla's database passwords,
 * Akaunting's and Statamic's .env and their session files. A first fix
 * (`chmod o-rwx`) locked the panel's own account out as well and every job
 * that starts in a site directory broke — so the panel is in the ACL too.
 */
beforeEach(function () {
    config(['server.panel_account' => 'panel', 'server.web_server' => 'nginx', 'server.web_server_user' => 'www-data']);

    $this->acl = new ArrayObject;          // home => printed getfacl lines
    $this->homes = new ArrayObject(['/home/alice' => 'directory|alice']);
    $this->aclInstalled = true;
    $this->ran = new ArrayObject;
    $this->user = SystemUser::create(['username' => 'alice', 'home_path' => '/home/alice', 'shell' => '/bin/bash', 'sudo' => false]);

    fakeHomeTools();
});

function fakeHomeTools(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        test()->ran[] = $args;

        return match ($args[0] ?? '') {
            'which' => Process::result(exitCode: test()->aclInstalled ? 0 : 1),
            'apt-get' => (function () {
                test()->aclInstalled = true;

                return Process::result();
            })(),
            'stat' => isset(test()->homes[end($args)])
                ? Process::result(output: ($args[2] ?? '') === '%a' ? '751' : test()->homes[end($args)])
                : Process::result(errorOutput: 'No such file', exitCode: 1),
            // setfacl -m <entries> <home>: what getfacl prints afterwards.
            'setfacl' => (function () use ($args) {
                if (($args[1] ?? '') === '-m') {
                    $lines = ['user::rwx', 'group::r-x', 'mask::r-x'];
                    foreach (explode(',', $args[2]) as $entry) {
                        $lines[] = str_starts_with($entry, 'u:') ? 'user:'.substr($entry, 2) : 'other::---';
                    }
                    test()->acl[end($args)] = $lines;
                }

                return Process::result();
            })(),
            'getfacl' => Process::result(output: implode("\n", test()->acl[end($args)] ?? ['user::rwx', 'group::r-x', 'other::--x'])."\n"),
            default => Process::result(),
        };
    });
}

function setfaclRuns(): array
{
    return collect(test()->ran)->filter(fn ($a) => ($a[0] ?? '') === 'setfacl')->values()->all();
}

it('closes a home to everyone but the panel and the web server', function () {
    expect(app(HomeDirectoryAccess::class)->secure($this->user))->toBe(HomeDirectoryAccess::SECURED)
        ->and(setfaclRuns())->toBe([['setfacl', '-m', 'u:panel:--x,u:www-data:--x,o::---', '/home/alice']]);
});

it('lets OpenLiteSpeed\'s worker account in, not www-data', function () {
    config(['server.web_server' => 'openlitespeed']);
    $this->mock(WebServerManager::class, function ($mock) {
        $driver = Mockery::mock(WebServerDriver::class);
        $driver->shouldReceive('siteReaderUser')->andReturn('nobody');
        $mock->shouldReceive('driver')->andReturn($driver);
    });

    app(HomeDirectoryAccess::class)->secure($this->user);

    expect(setfaclRuns()[0][2])->toBe('u:panel:--x,u:nobody:--x,o::---');
});

it('reports a home closed only when the ACL reads back as closed', function () {
    // setfacl exits 0 but the entries never appear: not closed.
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return match ($args[0] ?? '') {
            'stat' => Process::result(output: 'directory|alice'),
            'getfacl' => Process::result(output: "user::rwx\ngroup::r-x\nother::--x\n"),
            default => Process::result(),
        };
    });

    expect(app(HomeDirectoryAccess::class)->secure($this->user))->toBe(HomeDirectoryAccess::FAILED);
});

it('does nothing to a home that is already closed', function () {
    app(HomeDirectoryAccess::class)->secure($this->user);
    $this->ran->exchangeArray([]);

    expect(app(HomeDirectoryAccess::class)->secure($this->user))->toBe(HomeDirectoryAccess::ALREADY)
        ->and(setfaclRuns())->toBe([]);
});

it('leaves alone anything that is not the user\'s own home', function (string $home, ?string $stat) {
    $this->user->update(['home_path' => $home]);
    $this->homes->exchangeArray($stat === null ? [] : [$home => $stat]);

    expect(app(HomeDirectoryAccess::class)->secure($this->user->fresh()))->toBe(HomeDirectoryAccess::SKIPPED)
        ->and(setfaclRuns())->toBe([]);
})->with([
    'outside the home base' => ['/srv/alice', 'directory|alice'],
    'named after someone else' => ['/home/bob', 'directory|bob'],
    'owned by someone else' => ['/home/alice', 'directory|root'],
    'a link' => ['/home/alice', 'symbolic link|alice'],
    'missing' => ['/home/alice', null],
]);

it('does not close a home when the web server\'s account is unknown', function () {
    config(['server.web_server_user' => '']);

    // Closed, the web server could not enter it: every site of the user 403.
    expect(app(HomeDirectoryAccess::class)->secure($this->user))->toBe(HomeDirectoryAccess::NO_READER)
        ->and(setfaclRuns())->toBe([]);
});

it('installs acl when setfacl is missing, which Ubuntu 26.04 does not ship', function () {
    $this->aclInstalled = false;

    expect(app(HomeDirectoryAccess::class)->ensureTools())->toBeTrue()
        ->and(collect($this->ran)->contains(fn ($a) => ($a[0] ?? '') === 'apt-get' && in_array('acl', $a, true)))->toBeTrue();
});

it('closes every home on sites:resync, installing acl first', function () {
    $this->aclInstalled = false;
    SystemUser::create(['username' => 'bob', 'home_path' => '/home/bob', 'shell' => '/bin/bash', 'sudo' => false]);
    $this->homes['/home/bob'] = 'directory|bob';

    $this->artisan('sites:resync')
        ->expectsOutputToContain('Home directories: 2 closed to other users')
        ->assertSuccessful();

    expect(collect(setfaclRuns())->pluck(3)->all())->toBe(['/home/alice', '/home/bob']);
});

it('closes a new user\'s home at creation, and falls back to the old o+x without setfacl', function () {
    $access = app(HomeDirectoryAccess::class);

    expect($access->closeNewHome('carol', '/home/carol'))->toBeNull()
        ->and(setfaclRuns())->toHaveCount(1);

    $this->aclInstalled = false;
    $this->ran->exchangeArray([]);

    // Never locks the sites out over a missing tool.
    expect($access->closeNewHome('dave', '/home/dave')?->ok)->toBeTrue()
        ->and($this->ran->getArrayCopy())->toContain(['chmod', 'o+x', '/home/dave']);
});

it('reopens every home on homes:open', function () {
    app(HomeDirectoryAccess::class)->secure($this->user);
    $this->ran->exchangeArray([]);

    $this->artisan('homes:open')->expectsOutputToContain('reopened: 1')->assertSuccessful();

    expect($this->ran->getArrayCopy())->toContain(['setfacl', '-b', '/home/alice'])
        ->toContain(['chmod', 'o+x', '/home/alice']);
});

it('tells the Doctor which homes are still open', function () {
    $outcome = app(HomeAccessCheck::class)->run();

    expect($outcome['status'])->toBe('warn')
        ->and($outcome['detail'])->toContain('alice')
        ->and($outcome['fix'])->toBe('doctor.fixes.home_open');
});
