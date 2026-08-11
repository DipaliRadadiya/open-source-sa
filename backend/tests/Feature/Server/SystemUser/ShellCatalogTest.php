<?php

use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Shells, in words.
 *
 * The allowlist has always existed, but only inside a FormRequest constant —
 * so the panel could show the five paths and nothing else. `/usr/sbin/nologin`
 * looks like the other four to anyone who has not administered a Linux box,
 * and it is the one that turns login off.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    Process::fake(fn () => Process::result(exitCode: 0));
});

it('publishes the shells with a label and a description, not just paths', function () {
    $shells = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/system-users/shells')
        ->assertOk()
        ->json('shells');

    expect(collect($shells)->pluck('value')->all())
        ->toBe(['/bin/bash', '/bin/sh', '/usr/bin/zsh', '/usr/sbin/nologin', '/bin/false']);

    foreach ($shells as $shell) {
        expect($shell['title'])->not->toBeEmpty()
            ->and($shell['description'])->not->toBeEmpty()
            // The label must not just be the path again — that is the state
            // this endpoint exists to fix.
            ->and($shell['title'])->not->toBe($shell['value']);
    }
});

it('marks which shells actually allow a login', function () {
    $shells = collect($this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/system-users/shells')->json('shells'))
        ->keyBy('value');

    expect($shells['/bin/bash']['allows_login'])->toBeTrue()
        ->and($shells['/usr/sbin/nologin']['allows_login'])->toBeFalse()
        ->and($shells['/bin/false']['allows_login'])->toBeFalse();
});

it('is not swallowed by the {systemUser} route', function () {
    // Registered after the wildcard, "shells" would be read as an id. The
    // request would 404 and the picker would silently have no options.
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/system-users/shells')
        ->assertOk()
        ->assertJsonStructure(['shells' => [['value', 'title', 'description', 'allows_login']]]);
});

it('translates the shell labels', function () {
    $english = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/system-users/shells')->json('shells.0.title');

    $japanese = $this->withHeaders([
        'Authorization' => "Bearer {$this->token}",
        'Accept-Language' => 'ja',
    ])->getJson('/api/system-users/shells')->json('shells.0.title');

    expect($japanese)->not->toBe($english)->and($japanese)->not->toBeEmpty();
});

it('refuses the catalog without the system_user permission', function () {
    $outsider = User::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.$outsider->createToken('t')->plainTextToken)
        ->getJson('/api/system-users/shells')
        ->assertForbidden();
});

describe('a system user in the API', function () {
    it('carries the label and login flag beside the raw path', function () {
        $user = SystemUser::create([
            'username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/usr/sbin/nologin',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/system-users/{$user->id}")
            ->assertOk()
            // The path stays: it is what usermod takes and what an admin
            // comparing against /etc/passwd expects to see.
            ->assertJsonPath('system_user.shell', '/usr/sbin/nologin')
            ->assertJsonPath('system_user.shell_allows_login', false);

        expect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/system-users/{$user->id}")->json('system_user.shell_title'))
            ->not->toBeEmpty()
            ->not->toBe('/usr/sbin/nologin');
    });

    it('shows an unrecognised shell as itself rather than as blank', function () {
        // A server adopted from another panel can carry any shell at all. An
        // empty label would read as "no shell"; null for allows_login says
        // "unknown", which is the truth.
        $user = SystemUser::create([
            'username' => 'adopted', 'home_path' => '/home/adopted', 'shell' => '/usr/bin/fish',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/system-users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('system_user.shell_title', '/usr/bin/fish')
            ->assertJsonPath('system_user.shell_allows_login', null);
    });
});
