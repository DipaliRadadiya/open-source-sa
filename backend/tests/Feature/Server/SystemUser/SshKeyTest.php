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
    Process::fake();
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
