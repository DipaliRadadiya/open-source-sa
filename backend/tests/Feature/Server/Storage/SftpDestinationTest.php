<?php

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\Drivers\SftpDriver;
use App\Services\Server\Backups\Storage\SftpHostKey;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use phpseclib3\Crypt\EC;

/*
 * SFTP destinations, from testing one end to end on a real server
 * (2026-09-23).
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function sftpPayload(array $config): array
{
    return [
        'name' => 'Backup box',
        'provider' => 'sftp',
        'config' => ['host' => 'backup.example.com', 'username' => 'backups', ...$config],
    ];
}

/*
 * `not a key` saved fine and failed at the first test — or the first backup.
 * Measured with phpseclib: garbage and a protected key given no passphrase
 * fail identically, so the answers below come from the key itself.
 */
it('refuses a private key it cannot use, and says why', function (callable $config, string $message) {
    $this->actingAs($this->admin)
        ->postJson('/api/integrations/storage/destinations', sftpPayload($config()))
        ->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $errors) => ($errors['config.private_key'][0] ?? null) === __($message));

    expect(StorageDestination::count())->toBe(0);
})->with([
    'garbage' => [fn () => ['private_key' => 'not a key'], 'storage.validation.sftp_key_invalid'],
    'a public key pasted' => [fn () => ['private_key' => EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH')], 'storage.validation.sftp_key_is_public'],
    'protected, no passphrase' => [fn () => ['private_key' => EC::createKey('Ed25519')->withPassword('s3cret')->toString('OpenSSH')], 'storage.validation.sftp_key_needs_passphrase'],
    'protected, wrong passphrase' => [fn () => ['private_key' => EC::createKey('Ed25519')->withPassword('s3cret')->toString('OpenSSH'), 'passphrase' => 'nope'], 'storage.validation.sftp_key_wrong_passphrase'],
]);

it('accepts a key it can use', function (callable $config) {
    $this->actingAs($this->admin)
        ->postJson('/api/integrations/storage/destinations', sftpPayload($config()))
        ->assertCreated();
})->with([
    'plain' => [fn () => ['private_key' => EC::createKey('Ed25519')->toString('OpenSSH')]],
    'protected, right passphrase' => [fn () => ['private_key' => EC::createKey('Ed25519')->withPassword('s3cret')->toString('OpenSSH'), 'passphrase' => 's3cret']],
    'a password instead' => [fn () => ['password' => 'hunter2']],
]);

it('checks a rotated key against the passphrase already stored', function () {
    $destination = StorageDestination::create([
        'name' => 'Backup box', 'provider' => StorageProvider::Sftp, 'prefix' => null,
        'config' => ['host' => 'backup.example.com', 'username' => 'backups', 'private_key' => 'old', 'passphrase' => 's3cret'],
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/integrations/storage/destinations/{$destination->id}", [
            'config' => ['private_key' => EC::createKey('Ed25519')->withPassword('s3cret')->toString('OpenSSH')],
        ])->assertOk();

    $this->actingAs($this->admin)
        ->patchJson("/api/integrations/storage/destinations/{$destination->id}", [
            'config' => ['private_key' => 'not a key'],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.private_key');
});

/*
 * flysystem's default five attempts retried a refused login too: one Test
 * with a mistyped password was five failed logins — fail2ban's default
 * `maxretry`, so the backup server banned this panel — and OpenSSH 10 dropped
 * the retries, so the answer was `unreachable` instead of the password.
 */
it('logs in once, not five times', function () {
    $destination = new StorageDestination(['name' => 'x', 'provider' => StorageProvider::Sftp]);
    $destination->config = ['host' => 'h', 'username' => 'u', 'password' => 'p'];

    expect(app(SftpDriver::class)->config($destination))->toHaveKey('maxTries', 0);
});

/*
 * A backup writes into `<domain>/…` and SFTP creates every parent of that;
 * the probe's sentinel sits directly in the folder, and SFTP creates parents
 * only — so a prefix that did not exist yet failed the test as `unreachable`
 * while backups to it worked.
 */
it('creates the destination folder before the probe writes into it', function () {
    $calls = new ArrayObject;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('makeDirectory')->with('')->andReturnUsing(function () use ($calls) {
        $calls[] = 'makeDirectory';

        return true;
    });
    $disk->shouldReceive('put')->andReturnUsing(function ($key, $payload) use ($calls, $disk) {
        $calls[] = 'put';
        $disk->shouldReceive('get')->with($key)->andReturn($payload);

        return true;
    });
    $disk->shouldReceive('delete')->andReturnTrue();
    $disk->shouldReceive('deleteDirectory')->andReturnTrue();

    $prober = new StorageConnectionProber(
        drivers: app(StorageDriverFactory::class),
        hostKeys: new SftpHostKey(fn () => new class
        {
            public function getServerPublicHostKey(): string|false
            {
                return false;
            }
        }),
        diskBuilder: fn () => $disk,
    );

    $destination = StorageDestination::create([
        'name' => 'Backup box', 'provider' => StorageProvider::Sftp, 'prefix' => 'site-a',
        'config' => ['host' => 'backup.example.com', 'username' => 'backups', 'password' => 'p'],
    ]);

    expect($prober->probe($destination)['success'])->toBeTrue()
        ->and($calls->getArrayCopy())->toBe(['makeDirectory', 'put']);
});
