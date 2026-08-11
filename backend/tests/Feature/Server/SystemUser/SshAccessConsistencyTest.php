<?php

use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * SSH access has to mean what it says.
 *
 * Two ways it did not. The `ssh-users` group nothing ever created, so every
 * grant ran `useradd -G ssh-users` / `usermod -aG ssh-users` against a group
 * that was not there and failed on any real server — invisible here, because
 * a faked Process never checks whether its arguments refer to anything. And
 * a user could hold SSH access alongside a shell that refuses login, which
 * sshd authenticates and then disconnects: the panel showed access the box
 * would not give.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    Process::fake();
});

describe('the ssh-users group', function () {
    it('is created before an account is put in it', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/system-users', [
                'username' => 'deploy',
                'shell' => '/bin/bash',
                'ssh_access' => true,
            ])->assertCreated();

        Process::assertRan(fn ($p) => $p->command === ['groupadd', '-f', 'ssh-users']);
    });

    it('is created before an existing account is granted access', function () {
        $user = SystemUser::create([
            'username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/system-users/{$user->id}/ssh", ['ssh_access' => true])
            ->assertOk();

        Process::assertRan(fn ($p) => $p->command === ['groupadd', '-f', 'ssh-users']);
        Process::assertRan(fn ($p) => $p->command === ['usermod', '-aG', 'ssh-users', 'deploy']);
    });

    it('is not created when access is being removed', function () {
        $user = SystemUser::create([
            'username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'ssh_access' => true,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/system-users/{$user->id}/ssh", ['ssh_access' => false])
            ->assertOk();

        // Revoking cannot need the group to exist — gpasswd -d on a missing
        // group fails, and failing to *remove* access is the worse direction.
        Process::assertNotRan(fn ($p) => $p->command === ['groupadd', '-f', 'ssh-users']);
    });
});

describe('SSH access and the login shell must agree', function () {
    it('refuses to create a user with SSH access and a shell that denies login', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/system-users', [
                'username' => 'deploy',
                'shell' => '/usr/sbin/nologin',
                'ssh_access' => true,
            ])
            ->assertJsonValidationErrors('ssh_access');

        // Refused before anything reached the server.
        expect(SystemUser::where('username', 'deploy')->exists())->toBeFalse();
        Process::assertNothingRan();
    });

    it('refuses to switch SSH access on for a user whose shell denies login', function () {
        $user = SystemUser::create([
            'username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/usr/sbin/nologin',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/system-users/{$user->id}/ssh", ['ssh_access' => true])
            ->assertJsonValidationErrors('ssh_access');

        expect($user->fresh()->ssh_access)->toBeFalse();
    });

    it('refuses to switch the shell to one that denies login while SSH access is on', function () {
        $user = SystemUser::create([
            'username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'ssh_access' => true,
        ]);

        // The same contradiction from the other side. Refused rather than
        // silently switching SSH off, so the panel never changes a setting
        // the user did not touch.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/system-users/{$user->id}/shell", ['shell' => '/usr/sbin/nologin'])
            ->assertJsonValidationErrors('shell');

        expect($user->fresh()->shell)->toBe('/bin/bash');
    });

    it('allows a non-login shell once SSH access is off', function () {
        $user = SystemUser::create([
            'username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'ssh_access' => false,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/system-users/{$user->id}/shell", ['shell' => '/usr/sbin/nologin'])
            ->assertOk();

        expect($user->fresh()->shell)->toBe('/usr/sbin/nologin');
    });

    it('still allows a non-login shell with no SSH access at creation', function () {
        // The normal case for a site owner: owns the files, runs the site,
        // never logs in.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/system-users', [
                'username' => 'siteowner',
                'shell' => '/usr/sbin/nologin',
            ])->assertCreated();

        expect(SystemUser::where('username', 'siteowner')->first())
            ->shell->toBe('/usr/sbin/nologin')
            ->ssh_access->toBeFalse();
    });
});

it('defaults a new system user to no SSH access', function () {
    // The migration used to default this to true while the create action
    // always wrote false, so the schema documented the opposite of the panel.
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/system-users', ['username' => 'deploy'])
        ->assertCreated()
        ->assertJsonPath('system_user.ssh_access', false);

    expect(SystemUser::create([
        'username' => 'direct', 'home_path' => '/home/direct',
    ])->fresh()->ssh_access)->toBeFalse();
});
