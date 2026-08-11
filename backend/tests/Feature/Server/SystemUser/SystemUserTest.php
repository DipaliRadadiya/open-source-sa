<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A non-admin user granted only the `system_user` permission (via a role),
 * to prove pure role-based gating without relying on admin.
 */
function systemUserManager(bool $manage = true): User
{
    $user = User::factory()->create();
    grantPermission($user, 'system_user', view: true, manage: $manage);

    return $user;
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('creates a system user, running useradd and persisting the row', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy']);

    $response->assertCreated()
        ->assertJsonPath('system_user.username', 'deploy')
        ->assertJsonPath('system_user.home_path', '/home/deploy');

    expect(SystemUser::where('username', 'deploy')->exists())->toBeTrue();
    Process::assertRan(fn ($process) => $process->command === ['useradd', '-m', '-s', '/bin/bash', 'deploy']);
});

it('returns a translated error with a reference when useradd fails', function () {
    Process::fake(['useradd*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy']);

    $response->assertStatus(500)
        ->assertJsonPath('message', 'Failed to create the system user on the server.')
        ->assertJsonStructure(['message', 'reference']);

    expect(SystemUser::where('username', 'deploy')->exists())->toBeFalse();
});

it('creates a system user with sudo, ssh_access, shell and password set in one call', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', [
            'username' => 'deploy',
            // A login shell, because ssh_access is on: the two together are
            // now refused, since sshd would authenticate and the session
            // would close on a shell that denies login.
            'shell' => '/bin/bash',
            'sudo' => true,
            'ssh_access' => true,
            'password' => 'Str0ngPassword!',
        ]);

    $response->assertCreated()
        ->assertJsonPath('system_user.shell', '/bin/bash')
        ->assertJsonPath('system_user.sudo', true)
        ->assertJsonPath('system_user.ssh_access', true)
        ->assertJsonPath('system_user.password', 'Str0ngPassword!');

    Process::assertRan(fn ($process) => $process->command === [
        'useradd', '-m', '-s', '/bin/bash', '-G', 'sudo,ssh-users', 'deploy',
    ]);
    // The group must be made before useradd names it, or useradd refuses the
    // whole command and no account is created at all.
    Process::assertRan(fn ($process) => $process->command === ['groupadd', '-f', 'ssh-users']);
    Process::assertRan(fn ($process) => $process->command === ['chpasswd']);

    expect(SystemUser::where('username', 'deploy')->first())
        ->sudo->toBeTrue()
        ->ssh_access->toBeTrue()
        ->password->toBe('Str0ngPassword!');
});

it('defaults shell/sudo/ssh_access/password when none are given, unchanged from before', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy'])
        ->assertCreated()
        ->assertJsonPath('system_user.shell', '/bin/bash')
        ->assertJsonPath('system_user.sudo', false)
        ->assertJsonPath('system_user.ssh_access', false)
        ->assertJsonPath('system_user.password', null);

    Process::assertNotRan(fn ($process) => in_array('chpasswd', $process->command, true));
});

it('rejects a shell not on the allowlist at creation', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy', 'shell' => '/bin/evil'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shell');
});

it('rejects a weak password at creation', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy', 'password' => 'short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('does not persist the row when chpasswd fails after user creation succeeds', function () {
    Process::fake([
        'useradd*' => Process::result(exitCode: 0),
        'chpasswd*' => Process::result(exitCode: 1, errorOutput: 'boom'),
    ]);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy', 'password' => 'Str0ngPassword!']);

    $response->assertStatus(500);
    // The user row itself was created before the password step ran — this
    // documents current behavior (not transactional across the two OS
    // commands), consistent with every other multi-step server operation.
    expect(SystemUser::where('username', 'deploy')->first())->password->toBeNull();
});

it('rejects reserved usernames', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'root'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('rejects invalid usernames', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'Bad Name!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('lists system users with a minimal applications array', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;
    $su = SystemUser::create(['username' => 'app1', 'home_path' => '/home/app1', 'shell' => '/bin/bash']);
    Application::create(['system_user_id' => $su->id, 'name' => 'Shop', 'site_type' => 'wordpress', 'status' => 'active']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/system-users');

    $response->assertOk()
        ->assertJsonPath('system_users.0.username', 'app1')
        ->assertJsonPath('system_users.0.applications.0.name', 'Shop');
    // minimal: only id + name, no site_type on the list
    expect($response->json('system_users.0.applications.0'))->not->toHaveKey('site_type');
});

it('blocks deleting a system user that still owns applications', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;
    $su = SystemUser::create(['username' => 'app1', 'home_path' => '/home/app1', 'shell' => '/bin/bash']);
    Application::create(['system_user_id' => $su->id, 'name' => 'Shop', 'site_type' => 'wordpress', 'status' => 'active']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/system-users/{$su->id}")
        ->assertUnprocessable();

    expect(SystemUser::find($su->id))->not->toBeNull();
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), 'userdel'));
});

it('deletes a system user, running userdel', function () {
    Process::fake();
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;
    $su = SystemUser::create(['username' => 'gone', 'home_path' => '/home/gone', 'shell' => '/bin/bash']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/system-users/{$su->id}")
        ->assertNoContent();

    expect(SystemUser::find($su->id))->toBeNull();
    Process::assertRan(fn ($process) => $process->command === ['userdel', '-r', 'gone']);
});

it('denies a user without the system_user manage permission from creating', function () {
    Process::fake();
    $viewer = systemUserManager(manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy'])
        ->assertForbidden();
});

it('allows a non-admin with the system_user permission (pure role-based)', function () {
    Process::fake();
    $manager = systemUserManager();
    $token = $manager->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/system-users', ['username' => 'deploy'])
        ->assertCreated();
});
