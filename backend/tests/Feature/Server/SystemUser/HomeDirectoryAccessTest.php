<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Doctor\Checks\HomeAccessCheck;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A system user's home is open to its user and to the web server — nobody else.
 *
 * Homes were `chmod o+x`, and on a live box (2026-09-26) that let one site's
 * user read another's PrestaShop and Joomla database passwords, Akaunting and
 * Statamic `.env` files, and Laravel session files — a logged-in admin session.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
    ]);
});

/**
 * A server where `$home` has mode `$mode`, owned by `$owner`, and the web
 * server's account is (or is not) in the site user's group.
 *
 * @return ArrayObject<int, array<int, string>> every command run
 */
function homeServer(string $mode = '751', string $type = 'directory', ?string $owner = null, bool $readerIsMember = true, bool $grantWorks = true): ArrayObject
{
    $commands = new ArrayObject;
    // Groups `gpasswd -a` added during the test, so `id` answers the way a
    // real server does after a grant.
    $granted = new ArrayObject;

    Process::fake(function ($process) use ($commands, $mode, $type, $owner, $readerIsMember, $grantWorks, $granted) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands->append($args);

        if (($args[0] ?? '') === 'stat') {
            $user = basename((string) end($args));

            return Process::result(output: "{$type}|".($owner ?? $user)."|{$mode}\n");
        }

        if (($args[0] ?? '') === 'gpasswd') {
            if (! $grantWorks) {
                return Process::result(exitCode: 1, errorOutput: 'gpasswd: permission denied');
            }

            $granted->append((string) end($args));
        }

        if (($args[0] ?? '') === 'id') {
            $groups = [...($readerIsMember ? SystemUser::query()->pluck('username')->all() : []), ...$granted];

            return Process::result(output: trim('www-data '.implode(' ', $groups)));
        }

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    return $commands;
}

function homeUser(string $name = 'shop', ?string $home = null): SystemUser
{
    return SystemUser::create(['username' => $name, 'home_path' => $home ?? "/home/{$name}"]);
}

function homeSite(SystemUser $user, bool $isolated = true): Application
{
    $site = Application::create([
        'system_user_id' => $user->id, 'name' => 'Shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active',
        'web_root' => '/', 'php_version' => '8.4',
    ]);

    $site->forceFill(['isolated_at' => $isolated ? now() : null])->save();

    return $site;
}

function ranCommand(ArrayObject $commands, array $command): bool
{
    return collect($commands)->contains(fn (array $args) => $args === $command);
}

describe('a new account', function () {
    it('lets the web server in by group, then closes the home to everyone else', function () {
        $commands = homeServer(readerIsMember: false);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/system-users', ['username' => 'deploy'])->assertCreated();

        $order = collect($commands)->values();

        expect(ranCommand($commands, ['gpasswd', '-a', 'www-data', 'deploy']))->toBeTrue()
            ->and(ranCommand($commands, ['chmod', 'o-rwx', '/home/deploy']))->toBeTrue()
            // `o+x` let the web server in and every other local account with it.
            ->and(ranCommand($commands, ['chmod', 'o+x', '/home/deploy']))->toBeFalse()
            // Granted first: closed before the grant is a home nothing can serve.
            ->and($order->search(fn ($a) => ($a[0] ?? '') === 'gpasswd'))
            ->toBeLessThan($order->search(fn ($a) => $a === ['chmod', 'o-rwx', '/home/deploy']));
    });

    it('keeps the old traversal bit when the grant did not take', function () {
        // Closing a home the web server cannot enter is every future site of
        // this user answering 403. Open is what every account was before this.
        $commands = homeServer(readerIsMember: false, grantWorks: false);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/system-users', ['username' => 'deploy'])->assertCreated();

        expect(ranCommand($commands, ['chmod', 'o-rwx', '/home/deploy']))->toBeFalse()
            ->and(ranCommand($commands, ['chmod', 'o+x', '/home/deploy']))->toBeTrue();
    });

    it('asks OpenLiteSpeed which account it runs as, rather than assuming www-data', function () {
        ServerCapability::query()->update(['stack' => 'ols', 'web_server' => 'openlitespeed']);
        $commands = new ArrayObject;
        Process::fake(function ($process) use ($commands) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $commands->append($args);

            return match (true) {
                ($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'httpd_config') => Process::result(output: "user lsuser\ngroup nogroup\n"),
                ($args[0] ?? '') === 'id' => Process::result(output: 'nogroup'),
                default => Process::result(exitCode: 0),
            };
        });

        app(HomeDirectoryAccess::class)->closeNewHome('deploy', '/home/deploy');

        expect(ranCommand($commands, ['gpasswd', '-a', 'lsuser', 'deploy']))->toBeTrue();
    });
});

describe('an existing home', function () {
    it('is closed when the web server is already in the group', function () {
        $user = homeUser();
        homeSite($user);
        $commands = homeServer('751');

        expect(app(HomeDirectoryAccess::class)->secure($user))->toBe(HomeDirectoryAccess::SECURED)
            // Only what "other" could do is taken; owner and group keep theirs.
            ->and(ranCommand($commands, ['chmod', 'o-rwx', '/home/shop']))->toBeTrue();
    });

    it('is left open while the web server is not in the group yet', function () {
        $user = homeUser();
        homeSite($user);
        $commands = homeServer('751', readerIsMember: false);

        expect(app(HomeDirectoryAccess::class)->secure($user))->toBe(HomeDirectoryAccess::NO_READER)
            ->and(collect($commands)->contains(fn ($args) => ($args[0] ?? '') === 'chmod'))->toBeFalse();
    });

    it('is closed for a user with no sites, since nothing is being served from it', function () {
        $user = homeUser();
        homeServer('751', readerIsMember: false);

        expect(app(HomeDirectoryAccess::class)->secure($user))->toBe(HomeDirectoryAccess::SECURED);
    });

    it('is left open for a PHP site in the shared pool', function () {
        // Those workers are www-data processes that started before any grant.
        $user = homeUser();
        homeSite($user, isolated: false);
        $commands = homeServer('751');

        expect(app(HomeDirectoryAccess::class)->secure($user))->toBe(HomeDirectoryAccess::SHARED_POOL)
            ->and(collect($commands)->contains(fn ($args) => ($args[0] ?? '') === 'chmod'))->toBeFalse();
    });

    it('is not touched when it is already closed', function () {
        $user = homeUser();
        $commands = homeServer('750');

        expect(app(HomeDirectoryAccess::class)->secure($user))->toBe(HomeDirectoryAccess::ALREADY)
            ->and(collect($commands)->contains(fn ($args) => ($args[0] ?? '') === 'chmod'))->toBeFalse();
    });

    it('never chmods through a symlink, a stranger\'s directory, or a home outside the base', function (string $type, ?string $owner, ?string $home) {
        // chmod follows a link. Only a real directory, the user's own, directly
        // under root's /home — where the user cannot replace the entry — is
        // one root will change the mode of.
        $user = homeUser('shop', $home);
        $commands = homeServer('751', $type, $owner);

        expect(app(HomeDirectoryAccess::class)->secure($user))->toBe(HomeDirectoryAccess::SKIPPED)
            ->and(collect($commands)->contains(fn ($args) => ($args[0] ?? '') === 'chmod'))->toBeFalse();
    })->with([
        'a symlink' => ['symbolic link', null, null],
        'owned by someone else' => ['directory', 'root', null],
        'outside the home base' => ['directory', null, '/var/www/shop'],
        'named for another account' => ['directory', null, '/home/other'],
    ]);
});

describe('sites:resync', function () {
    it('closes homes only after the web server has been reloaded', function () {
        $user = homeUser();
        homeSite($user);
        $commands = homeServer('751');

        $this->artisan('sites:resync')
            ->expectsOutputToContain('Home directories: 1 closed to other users')
            ->assertSuccessful();

        $order = collect($commands)->values();
        $reload = $order->search(fn ($args) => ($args[0] ?? '') === 'systemctl' && in_array('nginx', $args, true));
        $close = $order->search(fn ($args) => $args === ['chmod', 'o-rwx', '/home/shop']);

        expect($reload)->not->toBeFalse()
            ->and($close)->not->toBeFalse()
            ->and($reload)->toBeLessThan($close);
    });

    it('lets the web server into a site user\'s group, and reloads for it', function () {
        // The grant changes no config text, so without counting it the reload
        // would not happen and the new workers would never get the group.
        $user = homeUser();
        homeSite($user);
        $commands = homeServer('751', readerIsMember: false);

        $this->artisan('sites:resync')
            ->expectsOutputToContain('Web server access granted for 1 site(s)')
            ->assertSuccessful();

        expect(ranCommand($commands, ['gpasswd', '-a', 'www-data', 'shop']))->toBeTrue()
            // Granted this run and reloaded, so the home can close too.
            ->and(ranCommand($commands, ['chmod', 'o-rwx', '/home/shop']))->toBeTrue();
    });

    it('leaves homes alone when access was granted but the reload did not happen', function () {
        $user = homeUser();
        homeSite($user);
        $commands = new ArrayObject;
        Process::fake(function ($process) use ($commands) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $commands->append($args);

            return match (true) {
                ($args[0] ?? '') === 'stat' => Process::result(output: "directory|shop|751\n"),
                // Not a member: the grant runs, and counts.
                ($args[0] ?? '') === 'id' => Process::result(output: 'www-data'),
                // And the config test fails, so there is no reload.
                ($args[0] ?? '') === 'nginx' => Process::result(exitCode: 1, errorOutput: 'invalid'),
                default => Process::result(exitCode: 0),
            };
        });

        $this->artisan('sites:resync')
            ->expectsOutputToContain('Home directories left as they are')
            ->assertSuccessful();

        expect(ranCommand($commands, ['chmod', 'o-rwx', '/home/shop']))->toBeFalse();
    });
});

describe('the doctor', function () {
    it('passes when every home is closed', function () {
        homeUser();
        homeServer('750');

        expect(app(HomeAccessCheck::class)->run()['status'])->toBe('pass');
    });

    it('names a home that is still open', function () {
        homeUser('shop');
        homeServer('751');

        $outcome = app(HomeAccessCheck::class)->run();

        expect($outcome['status'])->toBe('warn')
            ->and($outcome['detail'])->toContain('shop')
            ->and($outcome['fix'])->toBe('doctor.fixes.home_open')
            ->and(__('doctor.fixes.home_open'))->not->toBe('doctor.fixes.home_open');
    });
});
