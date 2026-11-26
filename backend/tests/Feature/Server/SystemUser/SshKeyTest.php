<?php

use App\Models\SshKey;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\SshKeyManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// A valid ed25519 public key (test fixture).
const TEST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QdummyBASE64keyForTestingPurposesAXyz deploy@host';
// Same length as TEST_KEY (a whole 32-byte key), different content → different fingerprint.
const TEST_KEY_2 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QdummyBASE64keyForTestingPurposesAXy0 backup@host';
// Real keys from ssh-keygen (OpenSSH 9.6), public halves only.
const RSA_1024 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQC4X0VLa/qlRhSETAvqoZRd/HIvXjTvMeJIzlqJmg6hZnKQMJqbuLQJ9nRKdmFiz9Lu2ZM5mpFDWTuuhqY40K608ZvNzmp/WtdC4ek72Py6fUPxkNCy9PE9+eq3PysX5v0rJHZEz64owSfirW2QhrMwtoRnfXRI1Kveul51mmoyWQ== fixture@host';
const RSA_2048 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQChjarT1779kt/06QZtxEGizi5L/kLXz7IIMVK5DH78Ch9er1XI3kcTAsDsdzffyx5rw+NeduHd5tG8YW1z2mooRDHAEwqWUnJqQUYRcUmAA2Al25whlzlgopmOpxnY91SOWtWCV4toq2FPhG6tPhIA/evjcOiFHzklmHfa9MilJ7Jc5x5T35mjM282pjIVPWZOzLBdNdg5CUmkjrXW74SjvLuTLrSybqZxALS7MnW6+Kwq9cvjCfUkog5u7AaZdtlLUqdVpIxSxVbd6d/AsfkrgwaM2eA2UzqqODg7D2bo3tw+6j35oX80NUb2cV4XGzYE5zgx7HPG3mBvg6/Kb6GR fixture@host';
const DSA_KEY = 'ssh-dss AAAAB3NzaC1kc3MAAACBAK0vXYQ4r90njURwyzJD0fEWvsX4GP1T5GStM3vTY8Q2NJ7P4dbh43XOkdZ3ElCn03beTlsEtgIjnQfbmXqEX29co4Px+bs5gbzRCoJxcgxXnZqiXqeIITs7d3FlKeataLy29CCW1ncwJDEHbfnL48Z6dAEL2pPc1F1ltMpmEpMVAAAAFQD+5a/ZM48nyuCDhCKpm572M+UcWQAAAIABfJilen2pjKKWg8qvtCoiC0ACvC5YP6HQxDCcmz9Dn7V5FvXnuAux5hpxhh8JAsnrUqYqmFmJVMhx8WIl9rhCDUVd4ipf8FcjyYckS2r7K4K1hkQMhaaZMSfN7a3yiWFztRnk5ooWqA7zdzw6p2EVRKpgg0EoPVpQluPsDcn2CwAAAIBqTq1jj/zEhv1I5v4xQNKvfOMKXPZhbMNn5fbNizBFhMPgc+R572sYFsXrB/EZWMUzoFtGrz45w6inmNnOavK8K2XvsGfkZS53V1ROPcPzMgkM7gn2IeBMXUDHdXCveLsDJ766+VG+GZgRbudwaTlZtV/sRwewFdgUBd0Oie0WVw== fixture@host';
const ECDSA_KEY = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBCguwu0p73gccVW/zfKg0igRqXlLABwpeBWUnd0MpLhvcQKA8u4goapFIztRdpI4TtuqAICJfhAKRLYql37MOqA= fixture@host';
// A key put on the server by hand, never through the panel.
const HAND_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QhandyBASE64keyForTestingPurposesAXyz admin@laptop';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // sync() writes through ServerOps/ManagedFile (mkdir, tee), so the fake must perform the
    // commands that produce state the tests assert on, or authorized_keys never appears.
    $this->ran = [];

    Process::fake(function ($process) {
        $cmd = $process->command;
        $this->ran[] = $cmd;

        // sync() runs every step as the user; the fake acts on the command after `runuser -u <user> --`.
        if (($cmd[0] ?? '') === 'runuser') {
            $cmd = array_slice($cmd, 4);
        }

        $bin = $cmd[0] ?? '';

        if ($bin === 'mkdir') {
            File::ensureDirectoryExists(end($cmd), 0700);

            return Process::result(exitCode: 0);
        }
        if ($bin === 'cat') {
            return File::exists($cmd[1])
                ? Process::result(output: File::get($cmd[1]))
                : Process::result(exitCode: 1, errorOutput: "cat: {$cmd[1]}: No such file or directory");
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

it('returns the key already there when the same key is added again, unchanged', function () {
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
        ->assertOk()
        ->assertJsonPath('existing', true)
        ->assertJsonPath('ssh_key.name', 'first')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'deploy') && str_contains($m, 'first'));

    expect($this->su->sshKeys()->count())->toBe(1);
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
    // The user owns ~/.ssh and decides what every name in it points at. As root, a planted
    // `authorized_keys -> /root/.ssh/authorized_keys` would make the panel write into root's file.
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
    // authorized_keys is line-oriented: the panel would list one key while sshd honoured two.
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

/** The same key with its blob replaced, type and comment kept. */
function withBlob(string $key, string $blob): string
{
    [$type, , $comment] = explode(' ', $key, 3);

    return "{$type} {$blob} {$comment}";
}

/*
 * A key cut short while copying is still valid base64 and still starts with its type,
 * but sshd never accepts it.
 */
it('refuses a key that was cut short', function (callable $cut) {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", ['name' => 'cut', 'public_key' => $cut()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.public_key.0', __('errors/system-user.invalid_public_key'));

    expect($this->su->sshKeys()->count())->toBe(0);
})->with([
    'ed25519, first 40 characters' => [fn () => withBlob(TEST_KEY, substr(explode(' ', TEST_KEY)[1], 0, 40))],
    'ed25519, one byte short' => [fn () => withBlob(TEST_KEY, base64_encode(substr(base64_decode(explode(' ', TEST_KEY)[1]), 0, -1)))],
    'ed25519, a field too many' => [fn () => withBlob(TEST_KEY, base64_encode(base64_decode(explode(' ', TEST_KEY)[1]).pack('N', 1).'x'))],
    'rsa, modulus missing' => [fn () => withBlob(RSA_2048, base64_encode(substr(base64_decode(explode(' ', RSA_2048)[1]), 0, 4 + 7 + 4 + 3)))],
    'rsa, first 100 characters' => [fn () => withBlob(RSA_2048, substr(explode(' ', RSA_2048)[1], 0, 100))],
    'ecdsa, point cut' => [fn () => withBlob(ECDSA_KEY, substr(explode(' ', ECDSA_KEY)[1], 0, 60))],
    // Every byte still there, but sshd will not read it without the `=`.
    'ecdsa, closing = lost' => [fn () => withBlob(ECDSA_KEY, rtrim(explode(' ', ECDSA_KEY)[1], '='))],
]);

it('refuses a key whose blob is not the type it claims to be', function () {
    // Valid base64, but the blob names ssh-ed25519 and the line says ssh-rsa.
    $mislabelled = preg_replace('/^ssh-ed25519/', 'ssh-rsa', TEST_KEY);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", ['name' => 'x', 'public_key' => $mislabelled])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');
});

it('does not keep a key row when writing authorized_keys fails', function () {
    // A refused write must not leave the panel listing a key the server never received.
    Process::fake(fn ($p) => in_array('tee', $p->command, true)
        ? Process::result(exitCode: 1, errorOutput: 'Permission denied')
        : Process::result());

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/system-users/{$this->su->id}/ssh-keys", ['name' => 'laptop', 'public_key' => TEST_KEY])
        ->assertStatus(500)
        ->assertJsonStructure(['reference']);

    expect($this->su->sshKeys()->count())->toBe(0);
});

it('keeps the key row when rewriting authorized_keys after a removal fails', function () {
    // The key is still on the server and still grants access; the panel must not say it is gone.
    $key = $this->su->sshKeys()->create([
        'name' => 'laptop',
        'public_key' => TEST_KEY,
        'fingerprint' => app(SshKeyManager::class)->fingerprint(TEST_KEY),
    ]);

    Process::fake(fn ($p) => in_array('tee', $p->command, true)
        ? Process::result(exitCode: 1, errorOutput: 'Permission denied')
        : Process::result());

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/system-users/{$this->su->id}/ssh-keys/{$key->id}")
        ->assertStatus(500);

    expect(SshKey::find($key->id))->not->toBeNull();
});

/*
| sync() must not write the panel's keys over the whole file: a key added by hand
| (often the admin's own) would vanish on the next change in the panel.
*/

function writeAuthorizedKeys(string $content): string
{
    $file = test()->su->home_path.'/.ssh/authorized_keys';
    File::ensureDirectoryExists(dirname($file), 0700);
    File::put($file, $content);

    return $file;
}

function addKey(string $name, string $key)
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->postJson('/api/system-users/'.test()->su->id.'/ssh-keys', ['name' => $name, 'public_key' => $key]);
}

it('keeps keys and lines added by hand when a key is added and removed', function () {
    $file = writeAuthorizedKeys("# admin\n".HAND_KEY."\nfrom=\"10.0.0.1\" ".TEST_KEY_2."\n");

    addKey('laptop', TEST_KEY)->assertCreated();

    expect(File::get($file))->toContain(HAND_KEY)->toContain('# admin')->toContain('from="10.0.0.1"')
        ->toContain(SshKeyManager::BLOCK_BEGIN."\n".TEST_KEY."\n".SshKeyManager::BLOCK_END);

    $key = SshKey::where('name', 'laptop')->first();
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/system-users/{$this->su->id}/ssh-keys/{$key->id}")
        ->assertNoContent();

    expect(File::get($file))->toBe("# admin\n".HAND_KEY."\nfrom=\"10.0.0.1\" ".TEST_KEY_2."\n");
});

it('removes a deleted key from a file written before the panel marked its block', function () {
    // The old sync wrote bare lines, so the panel's keys looked like hand-added ones.
    $file = writeAuthorizedKeys(TEST_KEY."\n".TEST_KEY_2."\n".HAND_KEY."\n");

    foreach ([['keep', TEST_KEY], ['drop', TEST_KEY_2]] as [$name, $key]) {
        $this->su->sshKeys()->create(['name' => $name, 'public_key' => $key, 'fingerprint' => app(SshKeyManager::class)->fingerprint($key)]);
    }
    $drop = SshKey::where('name', 'drop')->first();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/system-users/{$this->su->id}/ssh-keys/{$drop->id}")
        ->assertNoContent();

    expect(File::get($file))->toBe(HAND_KEY."\n\n".SshKeyManager::BLOCK_BEGIN."\n".TEST_KEY."\n".SshKeyManager::BLOCK_END."\n");
});

it('does not write over a file it could not read', function () {
    $file = writeAuthorizedKeys(HAND_KEY."\n");

    Process::fake(function ($process) {
        $this->ran[] = $process->command;

        return in_array('cat', $process->command, true)
            ? Process::result(exitCode: 1, errorOutput: 'cat: authorized_keys: Permission denied')
            : Process::result();
    });

    addKey('laptop', TEST_KEY)->assertStatus(500);

    expect(collect($this->ran)->contains(fn ($c) => in_array('tee', $c, true)))->toBeFalse()
        ->and(File::get($file))->toBe(HAND_KEY."\n")
        ->and($this->su->sshKeys()->count())->toBe(0);
});

/** A hardware-token key: string type, 32-byte key, string application. */
function hardwareKey(): string
{
    $type = 'sk-ssh-ed25519@openssh.com';

    return $type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32).str_repeat("\x07", 32).pack('N', 4).'ssh:').' yubikey';
}

it('takes the keys sshd logs in with', function (string $key) {
    addKey('ok', $key === 'hardware' ? hardwareKey() : $key)->assertCreated();
})->with([
    'ed25519' => [TEST_KEY],
    'rsa 2048' => [RSA_2048],
    'ecdsa' => [ECDSA_KEY],
    'hardware token' => ['hardware'],
]);

it('refuses a key sshd would refuse, saying why', function (string $key, string $message) {
    addKey('bad', $key)->assertUnprocessable()
        ->assertJsonPath('errors.public_key.0', __($message, ['bits' => SshKeyManager::MIN_RSA_BITS]));

    expect($this->su->sshKeys()->count())->toBe(0);
})->with([
    'dsa' => [DSA_KEY, 'errors/system-user.dsa_public_key'],
    'rsa 1024' => [RSA_1024, 'errors/system-user.weak_rsa_public_key'],
]);

it('refuses the same keys when a system user is created with one', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/system-users', ['username' => 'newuser', 'public_key' => DSA_KEY])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_key');
});
