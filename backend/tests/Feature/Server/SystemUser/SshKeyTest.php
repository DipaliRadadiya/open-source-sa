<?php

use App\Models\SshKey;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\SshKeyManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// A valid ed25519 public key (test fixture).
const TEST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QdummyBASE64keyForTestingPurposesXyz deploy@host';
// Same valid base64 length as TEST_KEY, different content → different fingerprint.
const TEST_KEY_2 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QdummyBASE64keyForTestingPurposesXy0 backup@host';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // sync() now writes through ServerOps/ManagedFile (mkdir, tee) rather than
    // File::put, so the fake has to perform the ones that produce state the
    // tests assert on — otherwise authorized_keys never appears and the
    // assertions below would be checking nothing at all.
    $this->ran = [];

    Process::fake(function ($process) {
        $cmd = $process->command;
        $this->ran[] = $cmd;

        // sync() runs every step as the user; the fake acts on the command
        // underneath the `runuser -u <user> --` prefix.
        if (($cmd[0] ?? '') === 'runuser') {
            $cmd = array_slice($cmd, 4);
        }

        $bin = $cmd[0] ?? '';

        if ($bin === 'mkdir') {
            File::ensureDirectoryExists(end($cmd), 0700);

            return Process::result(exitCode: 0);
        }
        if ($bin === 'tee') {
            File::put($cmd[1], (string) $process->input);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
    // writable home so authorized_keys sync actually runs
    config(['server.home_base' => storage_path('framework/testing/home')]);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create([
        'username' => 'deploy',
        'home_path' => storage_path('framework/testing/home/deploy'),
        'shell' => '/bin/bash',
    ]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/home'));
});

it('adds an SSH key: persists it and writes authorized_keys', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", [
            'name' => 'laptop',
            'public_key' => TEST_KEY,
        ]);

    $response->assertCreated()
        ->assertJsonPath('ssh_key.name', 'laptop')
        ->assertJsonStructure(['ssh_key' => ['id', 'name', 'fingerprint']]);

    expect($this->su->sshKeys()->count())->toBe(1);
    $file = $this->su->home_path.'/.ssh/authorized_keys';
    expect(File::exists($file))->toBeTrue();
    expect(File::get($file))->toContain(TEST_KEY);
});

it('rejects an invalid public key', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", [
            'name' => 'bad',
            'public_key' => 'not-a-real-key',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');
});

it('rejects a duplicate SSH key', function () {
    $this->su->sshKeys()->create([
        'name' => 'first',
        'public_key' => TEST_KEY,
        'fingerprint' => app(SshKeyManager::class)->fingerprint(TEST_KEY),
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", [
            'name' => 'dup',
            'public_key' => TEST_KEY,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');
});

it('lists SSH keys', function () {
    $this->su->sshKeys()->create([
        'name' => 'one',
        'public_key' => TEST_KEY,
        'fingerprint' => 'SHA256:abc',
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/system-users/{$this->su->id}/ssh-keys")
        ->assertOk()
        ->assertJsonCount(1, 'ssh_keys')
        ->assertJsonPath('ssh_keys.0.name', 'one');
});

it('removes an SSH key and rewrites authorized_keys', function () {
    // seed two keys via the API so the file reflects both
    foreach ([['keep', TEST_KEY], ['drop', TEST_KEY_2]] as [$name, $key]) {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/system-users/{$this->su->id}/ssh-keys", ['name' => $name, 'public_key' => $key]);
    }
    $drop = SshKey::where('name', 'drop')->first();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/system-users/{$this->su->id}/ssh-keys/{$drop->id}")
        ->assertNoContent();

    expect(SshKey::find($drop->id))->toBeNull();
    $content = File::get($this->su->home_path.'/.ssh/authorized_keys');
    expect($content)->toContain(TEST_KEY)->not->toContain(TEST_KEY_2);
});

it('writes authorized_keys as the user, never as root', function () {
    // The user owns ~/.ssh and decides what every name in it points at. As
    // root, a planted `authorized_keys -> /root/.ssh/authorized_keys` had the
    // panel write the user's keys into root's file — reproduced 2026-09-23.
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", ['name' => 'laptop', 'public_key' => TEST_KEY])
        ->assertCreated();

    $ran = collect($this->ran);

    expect($ran)->not->toBeEmpty();

    foreach ($ran as $command) {
        expect(array_slice($command, 0, 4))->toBe(['runuser', '-u', 'deploy', '--']);
    }

    // And no ownership fix-up over a tree the user controls.
    expect($ran->contains(fn ($command) => in_array('chown', $command, true)))->toBeFalse();
});

it('refuses a public key that carries a second line', function () {
    // authorized_keys is line-oriented: the panel would list one key and
    // fingerprint while sshd honoured two — reproduced 2026-09-23.
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", [
            'name' => 'laptop',
            'public_key' => TEST_KEY."\n".TEST_KEY_2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');

    expect($this->su->sshKeys()->count())->toBe(0);
    Process::assertNothingRan();
});

it('refuses a key whose blob is not the type it claims to be', function () {
    // Valid base64, but the blob names ssh-ed25519 and the line says ssh-rsa.
    $mislabelled = preg_replace('/^ssh-ed25519/', 'ssh-rsa', TEST_KEY);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", ['name' => 'x', 'public_key' => $mislabelled])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');
});
