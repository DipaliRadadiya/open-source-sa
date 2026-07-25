<?php

use App\Models\Permission;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);
});

it('sets the system user password via chpasswd (never in the command)', function () {
    Process::fake();
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/password", ['password' => 'Password123'])
        ->assertNoContent();

    Process::assertRan(function ($process) {
        // the password is piped to stdin, not in the command array
        return $process->command === ['chpasswd']
            && ! str_contains(implode(' ', (array) $process->command), 'Password123');
    });

    // Operator decision: the plaintext password is persisted so an admin can
    // copy it later for server login.
    expect($this->su->fresh()->password)->toBe('Password123');
});

it('exposes the stored password on the system user resource', function () {
    Process::fake();
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/password", ['password' => 'Password123']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/system-users/{$this->su->id}")
        ->assertOk()
        ->assertJsonPath('system_user.password', 'Password123');
});

it('rejects a weak system user password', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/password", ['password' => 'short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('grants sudo, running usermod and persisting the flag', function () {
    Process::fake();
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/sudo", ['sudo' => true]);

    $response->assertOk()->assertJsonPath('system_user.sudo', true);
    expect($this->su->fresh()->sudo)->toBeTrue();
    Process::assertRan(fn ($p) => $p->command === ['usermod', '-aG', 'sudo', 'deploy']);
});

it('revokes sudo, running gpasswd', function () {
    Process::fake();
    $this->su->update(['sudo' => true]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/sudo", ['sudo' => false]);

    $response->assertOk()->assertJsonPath('system_user.sudo', false);
    expect($this->su->fresh()->sudo)->toBeFalse();
    Process::assertRan(fn ($p) => $p->command === ['gpasswd', '-d', 'deploy', 'sudo']);
});

it('changes the shell to an allowlisted value, running usermod', function () {
    Process::fake();
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/shell", ['shell' => '/usr/sbin/nologin']);

    $response->assertOk()->assertJsonPath('system_user.shell', '/usr/sbin/nologin');
    expect($this->su->fresh()->shell)->toBe('/usr/sbin/nologin');
    Process::assertRan(fn ($p) => $p->command === ['usermod', '-s', '/usr/sbin/nologin', 'deploy']);
});

it('rejects a shell outside the allowlist', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/shell", ['shell' => '/bin/evil'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shell');
});

it('enables SSH access, running usermod against the ssh-users group', function () {
    Process::fake();
    $this->su->update(['ssh_access' => false]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/ssh", ['ssh_access' => true]);

    $response->assertOk()->assertJsonPath('system_user.ssh_access', true);
    expect($this->su->fresh()->ssh_access)->toBeTrue();
    Process::assertRan(fn ($p) => $p->command === ['usermod', '-aG', 'ssh-users', 'deploy']);
});

it('disables SSH access, running gpasswd against the ssh-users group', function () {
    Process::fake();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/ssh", ['ssh_access' => false]);

    $response->assertOk()->assertJsonPath('system_user.ssh_access', false);
    expect($this->su->fresh()->ssh_access)->toBeFalse();
    Process::assertRan(fn ($p) => $p->command === ['gpasswd', '-d', 'deploy', 'ssh-users']);
});

it('returns a translated error with reference when the ssh op fails', function () {
    Process::fake(['*usermod*' => Process::result(output: '', errorOutput: 'nope', exitCode: 1)]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/ssh", ['ssh_access' => true])
        ->assertStatus(500)
        ->assertJsonStructure(['message', 'reference']);

    // flag unchanged from its default (true) because the OS op failed
    expect($this->su->fresh()->ssh_access)->toBeTrue();
});

it('denies a viewer (no manage permission) from toggling ssh access', function () {
    $viewer = User::factory()->create();
    $perm = Permission::firstWhere('name', 'system_user');
    $viewer->permissions()->attach($perm->id, ['view' => true, 'manage' => false]);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/system-users/{$this->su->id}/ssh", ['ssh_access' => false])
        ->assertForbidden();
});

it('returns a translated error with reference when a settings op fails', function () {
    Process::fake(['*usermod*' => Process::result(output: '', errorOutput: 'nope', exitCode: 1)]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/system-users/{$this->su->id}/sudo", ['sudo' => true])
        ->assertStatus(500)
        ->assertJsonStructure(['message', 'reference']);

    expect($this->su->fresh()->sudo)->toBeFalse();
});

it('denies a viewer (no manage permission) from changing settings', function () {
    $viewer = User::factory()->create();
    $perm = Permission::firstWhere('name', 'system_user');
    $viewer->permissions()->attach($perm->id, ['view' => true, 'manage' => false]);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/system-users/{$this->su->id}/sudo", ['sudo' => true])
        ->assertForbidden();
});
