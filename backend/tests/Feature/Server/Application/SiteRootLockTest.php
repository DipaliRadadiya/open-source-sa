<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationEnvironment;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Doctor\Checks\SiteRootLockCheck;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The site root is made immutable so its own user cannot rename it away.
 *
 * `{home}/{slug}` is root's, but it sits in the user's home, and renaming needs
 * write permission on the parent only. On a real server (2026-09-23) the site
 * user moved it aside and substituted a directory holding a symlink; enabling
 * Basic Auth then had root write and chown the link's target. `chattr +i` makes
 * the rename impossible — these tests guard the lock itself and every panel
 * operation that has to lift it.
 */
beforeEach(function () {
    $this->user = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner', 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->site = Application::forceCreate([
        'system_user_id' => $this->user->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);

    $this->root = '/home/siteowner/shop';

    // One object for the fake's state, so the Process fake (which reaches the
    // test through a proxy) can change it by handle.
    $this->fs = (object) ['locked' => false, 'stats' => [], 'ran' => [], 'chattrError' => null, 'present' => []];
});

/**
 * A server with one site root: `chattr` flips a flag `lsattr` reports, and
 * `stat` answers from a queue (or a real root-owned directory by default).
 */
function fakeLockedSiteRoot(): void
{
    Process::fake(function ($process) {
        $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        test()->fs->ran[] = $cmd;

        // Answered by what runs under `runuser -u <user> --`; recorded as-is.
        if ($cmd[0] === 'runuser') {
            $cmd = array_slice($cmd, 4);
        }

        // As on a real server: while the flag is on, nothing may be added to or
        // renamed within the site root itself. Without this a test can "pass"
        // an operation that never lifted the lock at all.
        $topLevel = fn (string $path): bool => dirname($path) === test()->root;

        if (test()->fs->locked && (
            ($cmd[0] === 'mv' && $topLevel(end($cmd)))
            || ($cmd[0] === 'tee' && $topLevel($cmd[1] ?? ''))
        )) {
            return Process::result(errorOutput: 'Operation not permitted', exitCode: 1);
        }

        return match ($cmd[0]) {
            'chattr' => test()->fs->chattrError !== null
                ? Process::result(errorOutput: test()->fs->chattrError, exitCode: 1)
                : (function () use ($cmd) {
                    test()->fs->locked = $cmd[1] === '+i';

                    return Process::result();
                })(),
            'lsattr' => Process::result(output: (test()->fs->locked ? '----i---------e-------' : '--------------e-------').' '.$cmd[2]),
            'stat' => Process::result(output: array_shift(test()->fs->stats) ?? 'directory|root|4242'),
            'test' => Process::result(exitCode: in_array(end($cmd), test()->fs->present, true) ? 0 : 1),
            default => Process::result(),
        };
    });
}

/** Commands as strings, in order, for asserting on sequence. */
function siteRootCommands(): array
{
    return array_map(
        fn (array $cmd): string => implode(' ', $cmd),
        test()->fs->ran,
    );
}

function siteRootPosition(string $needle): int
{
    foreach (siteRootCommands() as $i => $command) {
        if (str_starts_with($command, $needle)) {
            return $i;
        }
    }

    return -1;
}

describe('locking', function () {
    it('locks a root-owned site root', function () {
        fakeLockedSiteRoot();

        expect(app(SiteRootLock::class)->lock($this->site))->toBe(SiteRootLock::LOCKED)
            ->and($this->fs->locked)->toBeTrue()
            ->and(siteRootCommands())->toContain('chattr +i '.$this->root);
    });

    it('refuses a symlink where the site root should be', function () {
        // The substitute itself: locking it would pin the user's swap in place
        // and report the site as safe.
        $this->fs->stats = ['symbolic link|siteowner|99'];
        fakeLockedSiteRoot();

        expect(app(SiteRootLock::class)->lock($this->site))->toBe(SiteRootLock::UNSAFE)
            ->and(siteRootPosition('chattr +i'))->toBe(-1);
    });

    it('refuses a site root the user owns', function () {
        // What a renamed-and-recreated directory looks like: the user made it.
        $this->fs->stats = ['directory|siteowner|99'];
        fakeLockedSiteRoot();

        expect(app(SiteRootLock::class)->lock($this->site))->toBe(SiteRootLock::UNSAFE)
            ->and(siteRootPosition('chattr +i'))->toBe(-1);
    });

    it('never locks a home directory', function () {
        // A row from before slugs resolves to the home itself; an immutable
        // home is a user who cannot create a file anywhere.
        $this->site->forceFill(['slug' => null])->save();
        fakeLockedSiteRoot();

        expect(app(SiteRootLock::class)->lock($this->site->fresh('systemUser')))->toBe(SiteRootLock::UNSAFE)
            ->and($this->fs->ran)->toBe([]);
    });

    it('takes the flag back off when the directory changed underneath it', function () {
        // A swap between the check and the chattr would otherwise lock the
        // substitute. The inode is what gives it away.
        $this->fs->stats = ['directory|root|4242', 'directory|root|9999'];
        fakeLockedSiteRoot();

        expect(app(SiteRootLock::class)->lock($this->site))->toBe(SiteRootLock::UNSAFE)
            ->and(siteRootPosition('chattr -i'))->toBeGreaterThan(siteRootPosition('chattr +i'))
            ->and($this->fs->locked)->toBeFalse();
    });

    it('says the filesystem cannot, rather than failing, where there is no immutable flag', function () {
        $this->fs->chattrError = 'chattr: Operation not supported while setting flags on /home/siteowner/shop';
        fakeLockedSiteRoot();

        expect(app(SiteRootLock::class)->lock($this->site))->toBe(SiteRootLock::UNSUPPORTED);
    });
});

describe('operations that change the top of the site root', function () {
    it('lifts the flag for the operation and puts it back', function () {
        $this->fs->locked = true;
        fakeLockedSiteRoot();

        $during = app(SiteRootLock::class)->unlocked($this->site, fn () => test()->fs->locked);

        expect($during)->toBeFalse()
            ->and($this->fs->locked)->toBeTrue();
    });

    it('puts it back even when the operation throws', function () {
        $this->fs->locked = true;
        fakeLockedSiteRoot();

        expect(fn () => app(SiteRootLock::class)->unlocked($this->site, fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class, 'boom');

        expect($this->fs->locked)->toBeTrue();
    });

    it('does not lock a site that was not locked going in', function () {
        // Unsupported filesystem, or a site flagged unsafe: an unrelated edit
        // must not quietly lock it.
        fakeLockedSiteRoot();

        app(SiteRootLock::class)->unlocked($this->site, fn () => null);

        expect(siteRootPosition('chattr'))->toBe(-1);
    });

    it('lifts the flag to save a .env at the top of the site root', function () {
        $this->fs->locked = true;
        $this->fs->present = [$this->root.'/.env'];
        fakeLockedSiteRoot();

        // Throws if the save ran with the flag still on — the fake refuses the
        // write exactly as the kernel would.
        app(ApplicationEnvironment::class)->write($this->site, "APP_ENV=production\n");

        expect(siteRootPosition('chattr -i'))->toBeGreaterThan(-1)
            ->and(siteRootPosition('chattr -i'))->toBeLessThan(siteRootPosition('mv '.$this->root.'/.env.panel-tmp'))
            ->and($this->fs->locked)->toBeTrue();
    });

    it('handles a .env beside the code as the site user, and never as root', function () {
        // Inside public_html every name is the user's; as root, the editor
        // wrote, chowned and read back whatever a planted link pointed at.
        $beside = $this->site->codePath().'/.env';
        $this->fs->present = [$beside];
        fakeLockedSiteRoot();

        app(ApplicationEnvironment::class)->write($this->site, "APP_ENV=production\n");

        $touching = array_values(array_filter(
            $this->fs->ran,
            fn (array $cmd): bool => str_contains(implode(' ', $cmd), $this->site->codePath().'/.env')
                && $cmd[0] !== 'test',
        ));

        expect($touching)->not->toBeEmpty();

        foreach ($touching as $cmd) {
            expect(array_slice($cmd, 0, 4))->toBe(['runuser', '-u', 'siteowner', '--']);
        }

        expect(siteRootPosition('chattr'))->toBe(-1);
    });

    it('lifts the flag before a site is deleted', function () {
        $this->fs->locked = true;
        fakeLockedSiteRoot();

        app(ApplicationProvisioner::class)
            ->deprovision($this->site->fresh(['systemUser', 'certificate']), removeFiles: true);

        expect(siteRootPosition('chattr -i'))->toBeGreaterThan(-1)
            ->and(siteRootPosition('chattr -i'))->toBeLessThan(siteRootPosition('rm -rf '.$this->root))
            ->and($this->fs->locked)->toBeFalse();
    });
});

describe('existing sites', function () {
    it('are locked by sites:resync, and a suspicious one is named rather than locked', function () {
        $other = Application::forceCreate([
            'system_user_id' => $this->user->id,
            'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
            'site_type' => 'wordpress', 'serving_profile' => 'php',
            'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
        ]);

        // Queue drained by every stat the command makes; only the site roots'
        // answers matter, so answer by path instead.
        Process::fake(function ($process) {
            $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            test()->fs->ran[] = $cmd;

            if ($cmd[0] === 'stat') {
                return Process::result(output: str_ends_with(end($cmd), '/blog') ? 'directory|siteowner|7' : 'directory|root|1');
            }

            return Process::result();
        });

        $this->artisan('sites:resync')
            ->expectsOutputToContain('Site roots: 1 locked, 1 need attention.')
            ->expectsOutputToContain('Not locked: Blog (#'.$other->id.')')
            ->assertSuccessful();

        expect(siteRootCommands())->toContain('chattr +i '.$this->root)
            ->not->toContain('chattr +i /home/siteowner/blog');
    });
});

describe('health check', function () {
    it('warns about a site root that is not locked, and passes once it is', function () {
        fakeLockedSiteRoot();

        $check = app(SiteRootLockCheck::class);

        expect($check->run())->toMatchArray(['status' => 'warn', 'fix' => 'doctor.fixes.site_root_unlocked'])
            ->and($check->run()['detail'])->toContain('Shop');

        $this->fs->locked = true;

        expect($check->run()['status'])->toBe('pass');
    });
});

describe('provisioning', function () {
    it('locks the site root at the end, even when a step fails', function () {
        // A setup that dies halfway must not leave the root renamable.
        fakeLockedSiteRoot();
        Process::fake(function ($process) {
            $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            test()->fs->ran[] = $cmd;

            return match ($cmd[0]) {
                // The first thing provisioning creates: fail it.
                'mkdir' => Process::result(errorOutput: 'No space left on device', exitCode: 1),
                'chattr' => (function () use ($cmd) {
                    test()->fs->locked = $cmd[1] === '+i';

                    return Process::result();
                })(),
                'stat' => Process::result(output: 'directory|root|4242'),
                'getent' => Process::result(output: 'siteowner:x:1001:1001::/home/siteowner:/bin/bash'),
                default => Process::result(),
            };
        });

        expect(fn () => app(ApplicationProvisioner::class)->provision($this->site->fresh('systemUser')))
            ->toThrow(ProvisioningFailedException::class);

        expect($this->fs->locked)->toBeTrue()
            ->and(siteRootPosition('chattr +i'))->toBeGreaterThan(siteRootPosition('mkdir'));
    });
});

describe('a missing top-level directory', function () {
    it('is created with the flag lifted on a locked site, and the flag goes back on', function () {
        // `.panel` or `logs` gone from a site that is already locked: the plain
        // mkdir is refused, exactly as on a real server, and the retry has the
        // flag lifted for just that.
        $this->fs->locked = true;
        Process::fake(function ($process) {
            $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            test()->fs->ran[] = $cmd;

            return match ($cmd[0]) {
                'mkdir' => test()->fs->locked
                    ? Process::result(errorOutput: 'mkdir: cannot create directory: Operation not permitted', exitCode: 1)
                    : Process::result(),
                'chattr' => (function () use ($cmd) {
                    test()->fs->locked = $cmd[1] === '+i';

                    return Process::result();
                })(),
                'lsattr' => Process::result(output: (test()->fs->locked ? '----i---------e-------' : '--------------e-------').' x'),
                'stat' => Process::result(output: 'directory|root|4242'),
                default => Process::result(),
            };
        });

        $result = app(SiteRootLock::class)->ensureDirectory($this->site, $this->root.'/.panel', ['feature' => 'test']);

        expect($result->ok)->toBeTrue()
            ->and($this->fs->locked)->toBeTrue();
    });
});

it('does not log a new site\'s missing root as an error', function () {
    // Provisioning lifts the lock before it creates anything, so on a brand
    // new site the root is not there yet and `stat` exits 1. That is the
    // normal answer, and it was landing on the admin error dashboard as an
    // ERROR on every site creation (seen live, 2026-09-23).
    $logger = Mockery::spy();
    Log::shouldReceive('channel')->with('server-ops')->andReturn($logger);
    Process::fake(['*' => Process::result(errorOutput: "stat: cannot stat '/home/siteowner/shop': No such file or directory", exitCode: 1)]);

    expect(app(SiteRootLock::class)->lock($this->site))->toBe(SiteRootLock::MISSING);

    $logger->shouldNotHaveReceived('error');
});
