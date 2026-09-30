<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\SiteRootLock;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The Lock button for a site folder the panel did not create.
 *
 * A site server sync adopted has a folder its own user owns, and `lock()`
 * refuses that — it cannot tell an adopted site from a substitute. So the
 * user vouches for it and `adopt()` brings it into the panel's layout: root
 * owns it, the mode is untouched, and the lock goes on. Every refusal below is
 * a case where doing that would be unsafe or would lock the user out.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->user = SystemUser::create(['username' => 'brown', 'home_path' => '/home/brown']);

    $this->site = Application::forceCreate([
        'system_user_id' => $this->user->id,
        'name' => 'Brownsite', 'slug' => 'brownsite', 'domain' => 'brown.test',
        'site_type' => 'php', 'serving_profile' => 'php',
        'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);

    $this->root = '/home/brown/brownsite';

    // The folder as the fake server sees it. `swapAfterChown` models the site
    // user replacing the folder between the panel's check and its lock.
    $this->dir = (object) [
        'type' => 'directory', 'owner' => 'brown', 'group' => 'brown', 'mode' => '755', 'inode' => '4242',
        'locked' => false, 'groups' => 'brown', 'chattrError' => null, 'swapAfterChown' => false, 'ran' => [],
    ];
});

function fakeAdoptedRoot(): void
{
    Process::fake(function ($process) {
        $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $dir = test()->dir;
        $dir->ran[] = implode(' ', $cmd);

        return match ($cmd[0]) {
            'stat' => $cmd[2] === '%F|%U|%G|%a|%i'
                ? Process::result(output: "{$dir->type}|{$dir->owner}|{$dir->group}|{$dir->mode}|{$dir->inode}")
                : Process::result(output: "{$dir->type}|{$dir->owner}|{$dir->inode}"),
            'id' => Process::result(output: $dir->groups),
            'chown' => (function () use ($cmd, $dir) {
                $dir->owner = $cmd[2];

                if ($dir->swapAfterChown) {
                    $dir->inode = '9999';
                }

                return Process::result();
            })(),
            'chattr' => $dir->chattrError !== null
                ? Process::result(errorOutput: $dir->chattrError, exitCode: 1)
                : (function () use ($cmd, $dir) {
                    $dir->locked = $cmd[1] === '+i';

                    return Process::result();
                })(),
            'lsattr' => Process::result(output: ($dir->locked ? '----i---------e-------' : '--------------e-------').' '.$cmd[2]),
            default => Process::result(),
        };
    });
}

function rootLockUrl(): string
{
    return '/api/applications/'.test()->site->id.'/root-lock';
}

describe('adopting a site folder', function () {
    it('hands a folder its user owns to root, without following a link, and locks it', function () {
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::LOCKED)
            ->and($this->dir->owner)->toBe('root')
            ->and($this->dir->locked)->toBeTrue()
            ->and($this->dir->ran)->toContain('chown -h root '.$this->root)
            // The mode is never touched: chmod follows a symlink.
            ->and(collect($this->dir->ran)->contains(fn (string $c) => str_starts_with($c, 'chmod')))->toBeFalse();
    });

    it('just locks a folder that is already root\'s', function () {
        $this->dir->owner = 'root';
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::LOCKED)
            ->and(collect($this->dir->ran)->contains(fn (string $c) => str_starts_with($c, 'chown')))->toBeFalse();
    });

    it('refuses what is not a real folder', function () {
        $this->dir->type = 'symbolic link';
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::UNSAFE)
            ->and($this->dir->owner)->toBe('brown')
            ->and($this->dir->locked)->toBeFalse();
    });

    it('refuses a folder owned by somebody other than the site user', function () {
        $this->dir->owner = 'mallory';
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::FOREIGN_OWNER)
            ->and($this->dir->owner)->toBe('mallory');
    });

    it('refuses a folder its group or everyone can write to', function (string $mode) {
        $this->dir->mode = $mode;
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::WRITABLE)
            ->and($this->dir->owner)->toBe('brown');
    })->with(['775', '757']);

    it('refuses what would lock the user out of their own site', function (string $mode, string $groups) {
        $this->dir->mode = $mode;
        $this->dir->groups = $groups;
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::LOCKS_OUT_USER)
            ->and($this->dir->owner)->toBe('brown');
    })->with([
        'owner-only mode' => ['700', 'brown'],
        'group can read but not open' => ['740', 'brown'],
        'user not in the folder\'s group' => ['750', 'staff'],
    ]);

    it('never locks a folder swapped in while it was being handed over', function () {
        $this->dir->swapAfterChown = true;
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::UNSAFE)
            ->and($this->dir->locked)->toBeFalse()
            // Not handed back either: it is not the folder that was checked.
            ->and(collect($this->dir->ran)->filter(fn (string $c) => str_starts_with($c, 'chown'))->values()->all())
            ->toBe(['chown -h root '.$this->root]);
    });

    // The inode adopt() checked is the one lock() must find. Without it, a
    // folder swapped in after adopt()'s own re-check is locked as the site.
    it('never locks a folder whose inode is not the one it was handed', function () {
        $this->dir->owner = 'root';
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->lock($this->site, expectedInode: '1111'))->toBe(SiteRootLock::UNSAFE)
            ->and($this->dir->locked)->toBeFalse();
    });

    it('gives the folder back to its user when the lock cannot be set', function () {
        $this->dir->chattrError = 'chattr: Operation not supported while setting flags';
        fakeAdoptedRoot();

        expect(app(SiteRootLock::class)->adopt($this->site))->toBe(SiteRootLock::UNSUPPORTED)
            ->and($this->dir->owner)->toBe('brown')
            ->and($this->dir->ran)->toContain('chown -h brown '.$this->root);
    });
});

describe('the endpoints', function () {
    it('reports an adopted site as not locked', function () {
        fakeAdoptedRoot();

        $this->actingAs($this->admin)->getJson(rootLockUrl())
            ->assertOk()
            ->assertJsonPath('root_lock.status', 'unlocked')
            ->assertJsonPath('root_lock.path', $this->root);
    });

    it('locks it, logs it, and reports it locked', function () {
        fakeAdoptedRoot();

        $this->actingAs($this->admin)->postJson(rootLockUrl())
            ->assertOk()
            ->assertJsonPath('root_lock.status', 'locked');

        expect($this->dir->locked)->toBeTrue()
            ->and(ActivityLog::query()->where('type', 'application')->where('action', 'root_locked')->exists())->toBeTrue();
    });

    it('says why it refused, in words, and changes nothing', function () {
        $this->dir->mode = '700';
        fakeAdoptedRoot();

        $this->actingAs($this->admin)->postJson(rootLockUrl())
            ->assertStatus(422)
            ->assertJsonPath('code', 'root_lock_request_refused')
            ->assertJsonPath('message', __('errors/application.root_lock.locks_out_user', ['path' => $this->root]));

        expect($this->dir->owner)->toBe('brown')
            ->and(ActivityLog::query()->where('action', 'root_locked')->exists())->toBeFalse();
    });

    it('lets a viewer see the state but not lock', function () {
        fakeAdoptedRoot();
        $viewer = User::factory()->create();
        grantPermission($viewer, 'application', view: true, manage: false);

        $this->actingAs($viewer)->getJson(rootLockUrl())->assertOk();
        $this->actingAs($viewer)->postJson(rootLockUrl())->assertForbidden();

        expect($this->dir->owner)->toBe('brown');
    });

    it('refuses a user without the application permission at all', function () {
        fakeAdoptedRoot();

        $this->actingAs(User::factory()->create())->getJson(rootLockUrl())->assertForbidden();
    });
});
