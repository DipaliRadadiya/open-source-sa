<?php

use App\Models\FirewallRule;
use App\Models\SshKey;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\CentralTokenManager;
use App\Services\Server\SshKeyManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
| SSH keys across every system user, for central's key list (2026-10-28):
| one list, one key onto several users, removal by fingerprint.
*/

function centralKey(string $tail = 'Xyz'): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QcentrBASE64keyForTestingPurposes'.$tail.' central@laptop';
}

/**
 * authorized_keys reads and writes go to disk under a temp home; `tee` fails
 * for any user named in `$failFor`; ufw reports `$ufw`.
 */
function fakeKeyServer(array $failFor = [], string $ufw = 'inactive'): void
{
    Process::fake(function ($process) use ($failFor, $ufw) {
        $cmd = $process->command;
        $user = ($cmd[0] ?? '') === 'runuser' ? $cmd[2] : null;

        if ($user !== null) {
            $cmd = array_slice($cmd, 4);
        }

        return match ($cmd[0] ?? '') {
            'mkdir' => tap(Process::result(), fn () => File::ensureDirectoryExists(end($cmd), 0700)),
            'cat' => File::exists($cmd[1])
                ? Process::result(output: File::get($cmd[1]))
                : Process::result(exitCode: 1, errorOutput: "cat: {$cmd[1]}: No such file or directory"),
            'tee' => in_array($user, $failFor, true)
                ? Process::result(exitCode: 1, errorOutput: 'tee: Permission denied')
                : tap(Process::result(), fn () => File::put($cmd[1], (string) $process->input)),
            'ufw' => Process::result(output: "Status: {$ufw}\n"),
            default => Process::result(),
        };
    });
}

function keysFile(SystemUser $user): string
{
    $file = $user->home_path.'/.ssh/authorized_keys';

    return File::exists($file) ? File::get($file) : '';
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->home = storage_path('framework/testing/central-keys');
    $this->admin = User::factory()->admin()->create();
    $this->alice = SystemUser::create(['username' => 'alice', 'home_path' => $this->home.'/alice', 'shell' => '/bin/bash', 'ssh_access' => true]);
    $this->bob = SystemUser::create(['username' => 'bob', 'home_path' => $this->home.'/bob', 'shell' => '/bin/bash', 'ssh_access' => true]);
    fakeKeyServer();
});

afterEach(fn () => File::deleteDirectory($this->home));

function deployKey(array $body): TestResponse
{
    return test()->actingAs(test()->admin)->postJson('/api/ssh-keys/deploy', $body);
}

it('puts one key on several users in one call, and says so per user', function () {
    deployKey(['name' => 'Dipali laptop', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]])
        ->assertOk()
        ->assertJsonPath('results.0.system_user.username', 'alice')
        ->assertJsonPath('results.0.status', 'added')
        ->assertJsonPath('results.1.system_user.username', 'bob')
        ->assertJsonPath('results.1.status', 'added')
        ->assertJsonPath('warnings', []);

    expect(keysFile($this->alice))->toContain(centralKey())
        ->and(keysFile($this->bob))->toContain(centralKey());
});

it('answers "existing" for a user who already has the key, and adds no second copy', function () {
    deployKey(['name' => 'Dipali laptop', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id]]);

    deployKey(['name' => 'Renamed', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'existing')
        ->assertJsonPath('results.1.status', 'added');

    expect(SshKey::where('system_user_id', $this->alice->id)->pluck('name')->all())->toBe(['Dipali laptop'])
        ->and(substr_count(keysFile($this->alice), centralKey()))->toBe(1);
});

it('carries on past a user it could not write for', function () {
    fakeKeyServer(failFor: ['alice']);

    deployKey(['name' => 'k', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'failed')
        ->assertJsonPath('results.0.reference', fn ($r) => is_string($r) && $r !== '')
        ->assertJsonPath('results.1.status', 'added');

    expect(SshKey::where('system_user_id', $this->alice->id)->exists())->toBeFalse()
        ->and(keysFile($this->bob))->toContain(centralKey());
});

it('warns when the key cannot be used to log in yet', function () {
    $this->bob->forceFill(['ssh_access' => false])->save();
    fakeKeyServer(ufw: 'active');
    FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

    deployKey(['name' => 'k', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]])
        ->assertOk()
        ->assertJsonPath('results.0.warnings', [])
        ->assertJsonPath('results.1.warnings', ['ssh_access_off'])
        ->assertJsonPath('warnings', ['ssh_port_closed']);
});

it('does not warn about the port when a rule allows it', function () {
    fakeKeyServer(ufw: 'active');
    FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

    deployKey(['name' => 'k', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id]])
        ->assertJsonPath('warnings', []);
});

it('refuses a key sshd would refuse before touching any user', function () {
    deployKey(['name' => 'k', 'public_key' => 'ssh-dss AAAAB3NzaC1kc3MAAACBAK0vXYQ4r90njURwyzJD0fEWvsX4GP1T x', 'system_user_ids' => [$this->alice->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');

    deployKey(['name' => 'k', 'public_key' => centralKey(), 'system_user_ids' => [999]])
        ->assertJsonValidationErrors('system_user_ids.0');

    expect(SshKey::count())->toBe(0);
});

it('lists every key on the server with its user and type, and filters', function () {
    deployKey(['name' => 'one', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]]);
    deployKey(['name' => 'two', 'public_key' => centralKey('Xy0'), 'system_user_ids' => [$this->bob->id]]);
    $fingerprint = app(SshKeyManager::class)->fingerprint(centralKey());

    $all = $this->actingAs($this->admin)->getJson('/api/ssh-keys')->assertOk()->json('ssh_keys');
    expect($all)->toHaveCount(3)
        ->and($all[0])->toMatchArray(['type' => 'ssh-ed25519'])
        ->and($all[0])->not->toHaveKey('public_key');

    $this->getJson('/api/ssh-keys?fingerprint='.urlencode($fingerprint))->assertJsonCount(2, 'ssh_keys');
    $this->getJson("/api/ssh-keys?system_user_id={$this->bob->id}")->assertJsonCount(2, 'ssh_keys')
        ->assertJsonPath('ssh_keys.0.system_user.username', 'bob');
});

it('removes a key by fingerprint from everyone who has it', function () {
    deployKey(['name' => 'one', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]]);
    deployKey(['name' => 'keep', 'public_key' => centralKey('Xy0'), 'system_user_ids' => [$this->bob->id]]);

    $this->actingAs($this->admin)
        ->deleteJson('/api/ssh-keys', ['fingerprint' => app(SshKeyManager::class)->fingerprint(centralKey())])
        ->assertOk()
        ->assertJsonCount(2, 'results')
        ->assertJsonPath('results.0.status', 'removed');

    expect(keysFile($this->alice))->not->toContain(centralKey())
        ->and(keysFile($this->bob))->not->toContain(centralKey())
        ->and(keysFile($this->bob))->toContain(centralKey('Xy0'));
});

it('removes it only from the users named', function () {
    deployKey(['name' => 'one', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id, $this->bob->id]]);

    $this->actingAs($this->admin)
        ->deleteJson('/api/ssh-keys', ['fingerprint' => app(SshKeyManager::class)->fingerprint(centralKey()), 'system_user_ids' => [$this->bob->id]])
        ->assertOk()
        ->assertJsonCount(1, 'results');

    expect(keysFile($this->alice))->toContain(centralKey())
        ->and(keysFile($this->bob))->not->toContain(centralKey());
});

it('treats a fingerprint nobody has as already removed', function () {
    $this->actingAs($this->admin)
        ->deleteJson('/api/ssh-keys', ['fingerprint' => 'SHA256:'.str_repeat('A', 43)])
        ->assertOk()
        ->assertJsonPath('results', []);
});

it('lets a viewer list but not add or remove', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'system_user');

    $this->actingAs($viewer)->getJson('/api/ssh-keys')->assertOk();
    $this->actingAs($viewer)->postJson('/api/ssh-keys/deploy', ['name' => 'k', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id]])->assertForbidden();
    $this->actingAs($viewer)->deleteJson('/api/ssh-keys', ['fingerprint' => 'SHA256:'.str_repeat('A', 43)])->assertForbidden();

    expect(SshKey::count())->toBe(0);
});

it('works with the central token', function () {
    $token = app(CentralTokenManager::class)->enable()['central_token'];

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/ssh-keys/deploy', ['name' => 'From central', 'public_key' => centralKey(), 'system_user_ids' => [$this->alice->id]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'added');

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/ssh-keys')->assertJsonCount(1, 'ssh_keys');
});
