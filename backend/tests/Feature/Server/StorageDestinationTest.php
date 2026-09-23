<?php

use App\Enums\StorageProvider;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\SftpHostKey;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Ftp\UnableToAuthenticate as FtpUnableToAuthenticate;
use League\Flysystem\Ftp\UnableToResolveConnectionRoot;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // Tests should never reach a real endpoint. The probe takes a disk-builder
    // closure; we bind a Container-level override here so any
    // ServiceProvider-level singleton resolves to the same fake too.
    $this->fakeDisk = Storage::fake();

    $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
        // Return type is deliberately untyped. A typed `: Filesystem` here
        // would force the resolved `$this->fakeDisk` (a LocalFilesystemAdapter
        // returned by Storage::fake()) through PHP's covariant return-type
        // check, which turns the test into a test of PHP's type system rather
        // than the prober.
        fn (array $config) => test()->fakeDisk,
    ));
});

/**
 * A prober wired with the real driver factory and a host-key reader that never
 * opens a socket.
 *
 * The factory is real on purpose: classification is now the driver's job, so a
 * test that faked the factory would be asserting against its own stub instead
 * of against the code that runs in production.
 */
function makeProber(?callable $diskBuilder = null, ?string $fingerprint = null): StorageConnectionProber
{
    return new StorageConnectionProber(
        drivers: app(StorageDriverFactory::class),
        hostKeys: new SftpHostKey(fn (string $host, int $port) => new class($fingerprint)
        {
            public function __construct(private ?string $fingerprint) {}

            public function getServerPublicHostKey(): string|false
            {
                // The shape phpseclib returns: `<algo> <base64 key> [comment]`.
                return $this->fingerprint === null ? false : 'ssh-ed25519 '.base64_encode($this->fingerprint);
            }
        }),
        diskBuilder: $diskBuilder,
    );
}

function storageAdminAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/** A saved S3 destination with the bare minimum fields plus optional overrides. */
function makeDestination(array $overrides = []): StorageDestination
{
    $config = array_merge([
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'bucket' => 'backups-prod',
        'access_key' => 'AKIA_secret_value',
        'secret_key' => 'shhhh_secret_value',
    ], $overrides['config'] ?? []);

    unset($overrides['config']);

    return StorageDestination::create(array_merge([
        'name' => 'Work S3',
        'provider' => StorageProvider::S3,
        'prefix' => 'app1/',
        'config' => $config,
    ], $overrides));
}

/** The create payload for an S3 destination. */
function s3Payload(array $overrides = []): array
{
    return array_merge([
        'name' => 'New S3',
        'provider' => 's3',
        'prefix' => 'app1/',
        'config' => array_merge([
            'endpoint' => 'https://s3.amazonaws.com',
            'region' => 'us-east-1',
            'bucket' => 'backups-prod',
            'access_key' => 'AKIA_secret_value',
            'secret_key' => 'shhhh_secret_value',
        ], $overrides['config'] ?? []),
    ], array_diff_key($overrides, ['config' => null]));
}

it('lists destinations sorted by name without case bias and never returns secrets', function () {
    makeDestination(['name' => 'Case Zebra']);
    makeDestination(['name' => 'case apple']);
    makeDestination(['name' => 'CASE Banana']);

    $response = $this->withHeaders(storageAdminAuthHeader())->getJson('/api/integrations/storage/destinations');

    $response->assertOk();
    expect($response->json('storage_destinations'))->toHaveCount(3);
    expect(collect($response->json('storage_destinations'))->pluck('name')->all())
        ->toBe(['case apple', 'CASE Banana', 'Case Zebra']);

    // Defence in depth — scan the whole envelope for the secret values.
    $json = $response->getContent();
    expect($json)->not->toContain('AKIA_secret_value')
        ->and($json)->not->toContain('shhhh_secret_value');
});

it('shows one destination and never returns secrets', function () {
    $dest = makeDestination();

    $response = $this->withHeaders(storageAdminAuthHeader())->getJson("/api/integrations/storage/destinations/{$dest->id}");

    $response->assertOk();
    expect($response->json('storage_destination.provider'))->toBe('s3');
    expect($response->json('storage_destination.provider_title'))->toBe('S3-compatible');
    expect($response->json('storage_destination.has_credentials'))->toBeTrue();
    // Addressing detail is fine to echo; credentials are not.
    expect($response->json('storage_destination.config.bucket'))->toBe('backups-prod');
    expect($response->json('storage_destination.config'))->not->toHaveKey('access_key')
        ->and($response->json('storage_destination.config'))->not->toHaveKey('secret_key')
        ->and($response->getContent())->not->toContain('AKIA_secret_value')
        ->and($response->getContent())->not->toContain('shhhh_secret_value');
});

it('creates a destination and stores the credentials encrypted', function () {
    $response = $this->withHeaders(storageAdminAuthHeader())
        ->postJson('/api/integrations/storage/destinations', s3Payload());

    $response->assertCreated();
    $response->assertJsonPath('storage_destination.name', 'New S3');
    $response->assertJsonPath('storage_destination.provider', 's3');
    $response->assertJsonPath('storage_destination.has_credentials', true);
    expect($response->json('storage_destination.config'))->not->toHaveKey('access_key')
        ->and($response->json('storage_destination.config'))->not->toHaveKey('secret_key');

    $dest = StorageDestination::first();
    // The encrypted cast makes the model access return plaintext; the *raw*
    // (stored) value must not contain it.
    expect($dest->configValue('access_key'))->toBe('AKIA_secret_value')
        ->and($dest->configValue('secret_key'))->toBe('shhhh_secret_value')
        ->and($dest->getRawOriginal('config'))->not->toContain('AKIA_secret_value')
        ->and($dest->getRawOriginal('config'))->not->toContain('shhhh_secret_value');
});

it('defaults the region to us-east-1 when omitted', function () {
    $response = $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Regionless',
        'provider' => 's3',
        'config' => ['bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's'],
    ]);

    $response->assertCreated();
    expect(StorageDestination::first()->configValue('region'))->toBe('us-east-1');
});

it('requires a provider and refuses an unknown one', function () {
    // No silent 's3' default: guessing the provider is the bug the column
    // exists to end.
    $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Providerless',
        'config' => ['bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's'],
    ])->assertUnprocessable()->assertJsonValidationErrors('provider');

    $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Nonsense',
        'provider' => 'dropbox',
        'config' => ['bucket' => 'b'],
    ])->assertUnprocessable()->assertJsonValidationErrors('provider');

    expect(StorageDestination::count())->toBe(0);
});

it('rejects a duplicate name', function () {
    makeDestination(['name' => 'Work S3']);

    $this->withHeaders(storageAdminAuthHeader())
        ->postJson('/api/integrations/storage/destinations', s3Payload(['name' => 'Work S3']))
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('rejects an invalid endpoint hostname', function () {
    foreach (['http://s3.example.com', 'https://127.0.0.1', 'https://169.254.169.254', 'https://localhost'] as $endpoint) {
        $this->withHeaders(storageAdminAuthHeader())
            ->postJson('/api/integrations/storage/destinations', s3Payload([
                'name' => 'Bad endpoint '.$endpoint,
                'config' => ['endpoint' => $endpoint],
            ]))
            ->assertUnprocessable()->assertJsonValidationErrors('config.endpoint');
    }

    expect(StorageDestination::count())->toBe(0);
});

it('rejects an invalid bucket name', function () {
    $this->withHeaders(storageAdminAuthHeader())
        ->postJson('/api/integrations/storage/destinations', s3Payload([
            'name' => 'Bad bucket',
            'config' => ['bucket' => 'with spaces and slashes/'],
        ]))
        ->assertUnprocessable()->assertJsonValidationErrors('config.bucket');

    expect(StorageDestination::count())->toBe(0);
});

it('rejects a single-line violation on the name', function () {
    $this->withHeaders(storageAdminAuthHeader())
        ->postJson('/api/integrations/storage/destinations', s3Payload(['name' => "Line1\nLine2"]))
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('updates a destination and keeps the credentials when not sent', function () {
    $dest = makeDestination(['name' => 'Old name']);

    $response = $this->withHeaders(storageAdminAuthHeader())->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
        'name' => 'New name',
    ]);

    $response->assertOk();
    $response->assertJsonPath('storage_destination.name', 'New name');

    // Rename only — credentials should still match what we created with.
    $dest->refresh();
    expect($dest->name)->toBe('New name')
        ->and($dest->configValue('access_key'))->toBe('AKIA_secret_value')
        ->and($dest->configValue('secret_key'))->toBe('shhhh_secret_value');
});

it('keeps the other config keys when only one is patched', function () {
    $dest = makeDestination();

    // The merge, not the replace. Sending one key must not clear the rest —
    // a destination that loses its secret key on a bucket change keeps
    // working until the next backup runs, which is the worst moment to find
    // out.
    $this->withHeaders(storageAdminAuthHeader())
        ->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
            'config' => ['bucket' => 'a-different-bucket'],
        ])->assertOk();

    $dest->refresh();
    expect($dest->configValue('bucket'))->toBe('a-different-bucket')
        ->and($dest->configValue('access_key'))->toBe('AKIA_secret_value')
        ->and($dest->configValue('secret_key'))->toBe('shhhh_secret_value')
        ->and($dest->configValue('region'))->toBe('us-east-1');
});

it('rotates the credentials when new values are sent', function () {
    $dest = makeDestination();

    $this->withHeaders(storageAdminAuthHeader())->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
        'config' => ['access_key' => 'AKIA_rotated', 'secret_key' => 'shhhh_rotated'],
    ])->assertOk();

    $dest->refresh();
    expect($dest->configValue('access_key'))->toBe('AKIA_rotated')
        ->and($dest->configValue('secret_key'))->toBe('shhhh_rotated')
        ->and($dest->getRawOriginal('config'))->not->toContain('AKIA_rotated');
});

it('refuses to change the provider of an existing destination', function () {
    $dest = makeDestination();

    // The config blob's shape is defined by the provider, so switching it
    // would reinterpret a bucket and a secret key as a hostname and a
    // password. Refused outright rather than ignored: a request that asked
    // and got a 200 would reasonably believe it had worked.
    $this->withHeaders(storageAdminAuthHeader())
        ->patchJson("/api/integrations/storage/destinations/{$dest->id}", ['provider' => 'ftp'])
        ->assertUnprocessable()->assertJsonValidationErrors('provider');

    expect($dest->refresh()->provider)->toBe(StorageProvider::S3);
});

it('rejects an update with a duplicate name', function () {
    makeDestination(['name' => 'A']);
    $b = makeDestination(['name' => 'B']);

    $this->withHeaders(storageAdminAuthHeader())->patchJson("/api/integrations/storage/destinations/{$b->id}", [
        'name' => 'A',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('deletes a destination', function () {
    $dest = makeDestination();

    $this->withHeaders(storageAdminAuthHeader())->deleteJson("/api/integrations/storage/destinations/{$dest->id}")
        ->assertNoContent();

    expect(StorageDestination::find($dest->id))->toBeNull();
});

describe('FTP destinations', function () {
    it('creates one and reports the host without the password', function () {
        $response = $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
            'name' => 'NAS',
            'provider' => 'ftp',
            'prefix' => 'sites/',
            'config' => [
                'host' => 'backup.example.com',
                'username' => 'backups',
                'password' => 'ftp_secret_value',
                'root' => 'archive',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('storage_destination.provider', 'ftp')
            ->assertJsonPath('storage_destination.config.host', 'backup.example.com')
            ->assertJsonPath('storage_destination.config.port', 21)
            // Defaults applied by the driver, not stored as user input.
            ->assertJsonPath('storage_destination.config.ssl', true)
            ->assertJsonPath('storage_destination.config.passive', true)
            ->assertJsonPath('storage_destination.has_credentials', true);

        expect($response->json('storage_destination.config'))->not->toHaveKey('password')
            ->and($response->getContent())->not->toContain('ftp_secret_value');
    });

    it('builds a config that keeps TLS on by default and surfaces adapter errors', function () {
        $dest = StorageDestination::create([
            'name' => 'NAS', 'provider' => StorageProvider::Ftp, 'prefix' => null,
            'config' => ['host' => 'backup.example.com', 'username' => 'u', 'password' => 'p'],
        ]);

        $config = app(DestinationDisk::class)->config($dest);

        expect($config['driver'])->toBe('ftp')
            ->and($config['ssl'])->toBeTrue()
            ->and($config['passive'])->toBeTrue()
            ->and($config['port'])->toBe(21)
            // Same reason as S3: without it a failed write returns false and
            // a backup that never happened reports success.
            ->and($config['throw'])->toBeTrue();
    });

    it('honours an explicit TLS opt-out without silently re-enabling it', function () {
        $dest = StorageDestination::create([
            'name' => 'Legacy', 'provider' => StorageProvider::Ftp, 'prefix' => null,
            'config' => ['host' => 'old.example.com', 'username' => 'u', 'password' => 'p', 'ssl' => false],
        ]);

        // The default is on; a deliberate false must survive. `?? true` would
        // be wrong here and `?: true` catastrophically so — it would coerce
        // the user's explicit "no" back to "yes" and tell them they were
        // encrypted when they were not.
        expect(app(DestinationDisk::class)->config($dest)['ssl'])->toBeFalse();
    });

    it('classifies a rejected login as invalid credentials, not as unreachable', function () {
        $dest = StorageDestination::create([
            'name' => 'NAS', 'provider' => StorageProvider::Ftp, 'prefix' => null,
            'config' => ['host' => 'backup.example.com', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
            fn (array $config) => new class
            {
                public function put(string $key, mixed $contents, array $options = []): bool
                {
                    throw new FtpUnableToAuthenticate;
                }
            },
        ));

        // The old shared classifier fingerprinted AWS SDK exception names, so
        // this exact failure was reported as "unreachable" — sending the user
        // to check DNS when their password was wrong.
        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk()
            ->assertJsonPath('test.error_class', 'invalid_credentials');
    });
});

describe('SFTP destinations', function () {
    it('requires either a password or a private key', function () {
        $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
            'name' => 'Keyless',
            'provider' => 'sftp',
            'config' => ['host' => 'backup.example.com', 'username' => 'backups'],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.password');

        expect(StorageDestination::count())->toBe(0);
    });

    it('accepts a private key instead of a password and reports the auth method', function () {
        $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
            'name' => 'Keyed',
            'provider' => 'sftp',
            'config' => [
                'host' => 'backup.example.com',
                'username' => 'backups',
                'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nkey_secret_value\n-----END OPENSSH PRIVATE KEY-----",
            ],
        ])->assertCreated()
            ->assertJsonPath('storage_destination.config.auth_method', 'private_key')
            ->assertJsonPath('storage_destination.config.port', 22)
            ->assertJsonPath('storage_destination.has_credentials', true);

        expect(StorageDestination::first()->getRawOriginal('config'))->not->toContain('key_secret_value');
    });

    it('passes the key and not an empty password when authenticating by key', function () {
        $dest = StorageDestination::create([
            'name' => 'Keyed', 'provider' => StorageProvider::Sftp, 'prefix' => null,
            'config' => ['host' => 'h', 'username' => 'u', 'private_key' => 'KEY'],
        ]);

        $config = app(DestinationDisk::class)->config($dest);

        // An empty `password` is not the same as no password: phpseclib would
        // try to authenticate with it and fail before ever reaching the key.
        expect($config)->toHaveKey('privateKey')
            ->and($config)->not->toHaveKey('password');
    });

    it('records the host fingerprint on first use and then pins it', function () {
        $dest = StorageDestination::create([
            'name' => 'Box', 'provider' => StorageProvider::Sftp, 'prefix' => null,
            'config' => ['host' => 'backup.example.com', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
            fn (array $config) => test()->fakeDisk,
            fingerprint: 'the-host-key-bytes',
        ));

        expect($dest->configValue('host_fingerprint'))->toBeNull();

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk()
            ->assertJsonPath('test.success', true);

        $recorded = $dest->refresh()->configValue('host_fingerprint');
        expect($recorded)->toStartWith('sha256:');

        // And it is now handed to the adapter, so a host that starts
        // answering with a different key is refused rather than trusted.
        expect(app(DestinationDisk::class)->config($dest)['hostFingerprint'])->toBe($recorded);
    });

    it('never re-pins an already-known host', function () {
        $dest = StorageDestination::create([
            'name' => 'Box', 'provider' => StorageProvider::Sftp, 'prefix' => null,
            'config' => [
                'host' => 'backup.example.com', 'username' => 'u', 'password' => 'p',
                'host_fingerprint' => 'sha256:originalfingerprint',
            ],
        ]);

        $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
            fn (array $config) => test()->fakeDisk,
            fingerprint: 'a-different-host-key',
        ));

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")->assertOk();

        // If this overwrote, the pin would re-pin itself to whoever answered
        // and the whole check would be decorative.
        expect($dest->refresh()->configValue('host_fingerprint'))->toBe('sha256:originalfingerprint');
    });

    it('reports a changed host key as its own category, not as unreachable', function () {
        $dest = StorageDestination::create([
            'name' => 'Box', 'provider' => StorageProvider::Sftp, 'prefix' => null,
            'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'host_fingerprint' => 'sha256:x'],
        ]);

        $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
            fn (array $config) => new class
            {
                public function put(string $key, mixed $contents, array $options = []): bool
                {
                    throw UnableToEstablishAuthenticityOfHost::becauseTheAuthenticityCantBeEstablished('h');
                }
            },
        ));

        // The host IS reachable — that is the problem. "Could not connect"
        // would send the operator to check the firewall while a possible
        // interception went unmentioned.
        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk()
            ->assertJsonPath('test.error_class', 'host_key_mismatch');
    });

    it('still sees a changed host key when the adapter wraps it, which is the only way it ever arrives', function () {
        /*
         * The test above throws the host-key exception bare. **The real adapter
         * never does.** Measured against a live SFTP server on 2026-09-14:
         * Flysystem returns `UnableToWriteFile` with the
         * `UnableToEstablishAuthenticityOfHost` one level down in
         * `getPrevious()`, so the driver's `instanceof` checks were reading the
         * wrapper and matching nothing — a rejected host key was reported as
         * `unreachable`, sending an operator to check their firewall while a
         * possible interception went unmentioned.
         *
         * The bare-throw test passed throughout. It was a test about the fake.
         */
        $dest = StorageDestination::create([
            'name' => 'Box', 'provider' => StorageProvider::Sftp, 'prefix' => null,
            'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'host_fingerprint' => 'sha256:x'],
        ]);

        $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
            fn (array $config) => new class
            {
                public function put(string $key, mixed $contents, array $options = []): bool
                {
                    throw UnableToWriteFile::atLocation(
                        '.probe/sentinel.bin',
                        "The authenticity of host 'h' can't be established.",
                        UnableToEstablishAuthenticityOfHost::becauseTheAuthenticityCantBeEstablished('h'),
                    );
                }
            },
        ));

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk()
            ->assertJsonPath('test.error_class', 'host_key_mismatch');
    });
});

describe('the remote host guard', function () {
    it('refuses loopback, the metadata range and pasted URLs', function () {
        $blocked = [
            'localhost', '127.0.0.1', '169.254.169.254', '0.0.0.0', '::1',
            'https://backup.example.com', 'backup.example.com/path', 'user@backup.example.com',
        ];

        foreach ($blocked as $host) {
            $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
                'name' => 'Blocked '.$host,
                'provider' => 'ftp',
                'config' => ['host' => $host, 'username' => 'u', 'password' => 'p'],
            ])->assertUnprocessable()->assertJsonValidationErrors('config.host');
        }

        expect(StorageDestination::count())->toBe(0);
    });

    it('allows a private LAN address, because a NAS on the same network is the normal case', function () {
        $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
            'name' => 'LAN NAS',
            'provider' => 'ftp',
            'config' => ['host' => '192.168.1.50', 'username' => 'u', 'password' => 'p'],
        ])->assertCreated();
    });

    it('refuses a remote root that climbs out of the directory it was given', function () {
        $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
            'name' => 'Climber',
            'provider' => 'ftp',
            'config' => ['host' => 'backup.example.com', 'username' => 'u', 'password' => 'p', 'root' => 'backups/../../etc'],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.root');
    });
});

it('composes the remote root from the connection root and the prefix', function () {
    $dest = StorageDestination::create([
        'name' => 'NAS', 'provider' => StorageProvider::Ftp, 'prefix' => 'shop.example.com',
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => 'archive'],
    ]);

    // Stacked wrong, a backup lands one level up or in the account's home —
    // a real, valid, silently incorrect location.
    expect(app(DestinationDisk::class)->config($dest)['root'])->toBe('archive/shop.example.com');

    $noPrefix = StorageDestination::create([
        'name' => 'Bare', 'provider' => StorageProvider::Ftp, 'prefix' => null,
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p'],
    ]);

    // Empty, not "/" — a literal root means the filesystem root and fails for
    // every unprivileged account.
    expect(app(DestinationDisk::class)->config($noPrefix)['root'])->toBe('');
});

it('keeps an absolute remote root absolute, instead of resolving it against the login directory', function () {
    /*
     * `/backups` is the form every FTP and SFTP client accepts, so it is the
     * form users type. Trimming the leading slash turned it into the relative
     * `backups`, resolved against wherever the login lands.
     *
     * Verified against live servers on 2026-09-14, and the two protocols hid
     * it in opposite directions: SFTP creates missing directories, so a
     * destination set to `/backups` probed **green** while writing into
     * `~/backups` — every archive in a directory the operator never chose.
     * FTP refuses to resolve a root that does not exist, so the same
     * destination failed and blamed the network. The silent success is the
     * dangerous one.
     */
    $absolute = StorageDestination::create([
        'name' => 'Absolute', 'provider' => StorageProvider::Sftp, 'prefix' => 'shop.example.com',
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => '/srv/backups'],
    ]);

    expect(app(DestinationDisk::class)->config($absolute)['root'])->toBe('/srv/backups/shop.example.com');

    $relative = StorageDestination::create([
        'name' => 'Relative', 'provider' => StorageProvider::Sftp, 'prefix' => null,
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => 'srv/backups'],
    ]);

    // A relative root stays relative — the point is to carry across what the
    // user wrote, not to normalise one form into the other.
    expect(app(DestinationDisk::class)->config($relative)['root'])->toBe('srv/backups');

    $slashOnly = StorageDestination::create([
        'name' => 'Slash', 'provider' => StorageProvider::Sftp, 'prefix' => null,
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => '/'],
    ]);

    // Still '' — a bare slash in a form is indistinguishable from "not set",
    // and reading it literally breaks every unprivileged account.
    expect(app(DestinationDisk::class)->config($slashOnly)['root'])->toBe('');
});

it('says the folder is missing rather than blaming the network', function () {
    /*
     * The host answered and the login succeeded; only the directory is absent
     * — the single most likely mistake when creating a destination, since the
     * folder usually does not exist until someone makes it. Reported as
     * `unreachable` it sends the operator to check DNS and firewalls.
     */
    $dest = StorageDestination::create([
        'name' => 'NAS', 'provider' => StorageProvider::Ftp, 'prefix' => null,
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => 'not-created-yet'],
    ]);

    $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
        fn (array $config) => new class
        {
            public function put(string $key, mixed $contents, array $options = []): bool
            {
                // The shape the live FTP adapter produces: the real cause is
                // wrapped, exactly as with the SFTP host key.
                throw UnableToWriteFile::atLocation(
                    '.probe/sentinel.bin',
                    'creating parent directory failed',
                    UnableToResolveConnectionRoot::itDoesNotExist('not-created-yet'),
                );
            }
        },
    ));

    $this->withHeaders(storageAdminAuthHeader())
        ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
        ->assertOk()
        ->assertJsonPath('test.error_class', 'root_missing');
});

it('keeps an absolute remote root absolute', function () {
    $dest = StorageDestination::create([
        'name' => 'Absolute', 'provider' => StorageProvider::Ftp, 'prefix' => 'shop.example.com',
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => '/backups'],
    ]);

    // `/backups` is the form every FTP and SFTP client accepts. Stripping the
    // leading slash makes it relative to wherever the login lands, and the two
    // protocols hide that differently: SFTP creates the missing directory and
    // silently succeeds into `~/backups`, FTP refuses and reports an
    // unreachable host. The green probe against the wrong directory is worse.
    expect(app(DestinationDisk::class)->config($dest)['root'])->toBe('/backups/shop.example.com');

    $bare = StorageDestination::create([
        'name' => 'Slash only', 'provider' => StorageProvider::Ftp, 'prefix' => null,
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => '/'],
    ]);

    // A bare slash is indistinguishable from "not set" in a form, and reading
    // it literally breaks every unprivileged account.
    expect(app(DestinationDisk::class)->config($bare)['root'])->toBe('');
});

describe('the in-use guard', function () {
    beforeEach(function () {
        $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

        $this->makeApplication = function (string $name) use ($systemUser): Application {
            return Application::create([
                'system_user_id' => $systemUser->id,
                'name' => $name,
                'domain' => strtolower($name).'.example.test',
                'site_type' => 'php',
                'serving_profile' => 'php',
                'status' => 'active',
            ]);
        };
    });

    it('refuses to delete a destination a backup target still points at, naming the sites', function () {
        $dest = makeDestination();

        foreach (['Shop', 'Blog'] as $name) {
            BackupTarget::create([
                'application_id' => ($this->makeApplication)($name)->id,
                'storage_destination_id' => $dest->id,
                'type' => 'full',
                'retention_count' => 7,
                'frequency' => 'daily',
                'enabled' => true,
            ]);
        }

        $response = $this->withHeaders(storageAdminAuthHeader())
            ->deleteJson("/api/integrations/storage/destinations/{$dest->id}")
            ->assertStatus(422);

        // The database's restrictOnDelete would have refused this too — as a
        // 500 naming nothing. The point of the guard is the message.
        $message = $response->json('errors.storage_destination.0');
        expect($message)->toContain('Work S3')
            ->and($message)->toContain('Blog')
            ->and($message)->toContain('Shop');

        expect(StorageDestination::find($dest->id))->not->toBeNull();
    });

    it('collapses a long list of applications into a count', function () {
        $dest = makeDestination();

        foreach (range(1, 8) as $i) {
            BackupTarget::create([
                'application_id' => ($this->makeApplication)("Site{$i}")->id,
                'storage_destination_id' => $dest->id,
                'type' => 'full',
                'retention_count' => 7,
                'frequency' => 'daily',
                'enabled' => true,
            ]);
        }

        $message = $this->withHeaders(storageAdminAuthHeader())
            ->deleteJson("/api/integrations/storage/destinations/{$dest->id}")
            ->assertStatus(422)
            ->json('errors.storage_destination.0');

        // Five named, the rest counted — a destination shared by forty sites
        // must not produce a multi-kilobyte error string.
        expect($message)->toContain('Site5')
            ->and($message)->not->toContain('Site6')
            ->and($message)->toContain('3 more');
    });

    it('deletes once the last backup target is gone', function () {
        $dest = makeDestination();

        $target = BackupTarget::create([
            'application_id' => ($this->makeApplication)('Shop')->id,
            'storage_destination_id' => $dest->id,
            'type' => 'full',
            'retention_count' => 7,
            'frequency' => 'daily',
            'enabled' => true,
        ]);

        $target->delete();

        $this->withHeaders(storageAdminAuthHeader())
            ->deleteJson("/api/integrations/storage/destinations/{$dest->id}")
            ->assertNoContent();

        expect(StorageDestination::find($dest->id))->toBeNull();
    });
});

describe('the persisted test result', function () {
    it('starts as never tested', function () {
        $dest = makeDestination();

        $this->withHeaders(storageAdminAuthHeader())
            ->getJson("/api/integrations/storage/destinations/{$dest->id}")
            ->assertOk()
            // Null, not false: "never asked" and "asked and it failed" are
            // different answers and the UI shows different things for them.
            ->assertJsonPath('storage_destination.last_test_success', null)
            ->assertJsonPath('storage_destination.last_tested_at', null)
            ->assertJsonPath('storage_destination.status', 'never_tested')
            ->assertJsonPath('storage_destination.status_title', 'Not yet tested');
    });

    it('survives a reload after a successful probe', function () {
        $dest = makeDestination();

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk();

        // A second request, as if the user navigated away and came back.
        $response = $this->withHeaders(storageAdminAuthHeader())
            ->getJson("/api/integrations/storage/destinations/{$dest->id}")
            ->assertOk()
            ->assertJsonPath('storage_destination.last_test_success', true)
            ->assertJsonPath('storage_destination.last_test_error', null)
            ->assertJsonPath('storage_destination.status', 'connected');

        expect($response->json('storage_destination.last_tested_at'))->not->toBeNull()
            ->and($response->json('storage_destination.last_tested_at_human'))->not->toBeNull();
    });

    it('records a failure as its stable category, never the raw exception', function () {
        $dest = makeDestination();

        $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
            fn (array $config) => new class
            {
                public function put(string $key, mixed $contents, array $options = []): bool
                {
                    throw new RuntimeException('InvalidAccessKeyId: AKIA_secret_value is not valid');
                }
            },
        ));

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk();

        $response = $this->withHeaders(storageAdminAuthHeader())
            ->getJson("/api/integrations/storage/destinations/{$dest->id}")
            ->assertOk()
            ->assertJsonPath('storage_destination.last_test_success', false)
            ->assertJsonPath('storage_destination.last_test_error', 'invalid_credentials')
            ->assertJsonPath('storage_destination.status', 'failed');

        // The SDK's text can carry a partial access key. Only the category
        // is stored, so it can never resurface in a later response.
        expect($response->getContent())->not->toContain('AKIA_secret_value');
    });

    it('forgets the result when the credentials are rotated', function () {
        $dest = makeDestination();

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk();

        // A green tick describing keys that were replaced a moment ago is
        // worse than no tick at all.
        $this->withHeaders(storageAdminAuthHeader())
            ->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
                'config' => ['access_key' => 'AKIA_rotated', 'secret_key' => 'rotated_secret'],
            ])
            ->assertOk()
            ->assertJsonPath('storage_destination.status', 'never_tested')
            ->assertJsonPath('storage_destination.last_test_success', null);
    });

    it('forgets the result when the host changes, not only the credentials', function () {
        $dest = StorageDestination::create([
            'name' => 'NAS', 'provider' => StorageProvider::Ftp, 'prefix' => null,
            'config' => ['host' => 'old.example.com', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")->assertOk();

        // The old invalidation list was five hardcoded S3 column names, which
        // could only ever be right for one provider. A host change slipping
        // past it leaves a green badge describing a destination that is no
        // longer the one being talked to.
        $this->withHeaders(storageAdminAuthHeader())
            ->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
                'config' => ['host' => 'new.example.com'],
            ])
            ->assertOk()
            ->assertJsonPath('storage_destination.status', 'never_tested');
    });

    it('keeps the result when only the display name changes', function () {
        $dest = makeDestination();

        $this->withHeaders(storageAdminAuthHeader())
            ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
            ->assertOk();

        // A rename does not change what the panel talks to.
        $this->withHeaders(storageAdminAuthHeader())
            ->patchJson("/api/integrations/storage/destinations/{$dest->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('storage_destination.status', 'connected');
    });
});

it('has copy for every provider, status and failure category in every locale', function () {
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach (StorageProvider::cases() as $provider) {
            expect(__('storage.drivers.'.$provider->value))->not->toBe('storage.drivers.'.$provider->value);
        }

        foreach (['connected', 'never_tested', 'failed'] as $status) {
            expect(__('storage.status.'.$status))->not->toBe('storage.status.'.$status);
        }

        // Every category a driver can return. A missing one shows the user a
        // raw key at precisely the moment something has gone wrong.
        foreach ([
            'success', 'invalid_credentials', 'unreachable', 'mismatch',
            'forbidden_host', 'invalid_endpoint', 'invalid_host',
            'host_key_mismatch', 'invalid_private_key',
        ] as $key) {
            expect(__('storage.test.'.$key))->not->toBe('storage.test.'.$key);
        }

        foreach (['in_use', 'and_more'] as $key) {
            expect(__('storage.delete.'.$key))->not->toBe('storage.delete.'.$key);
        }

        expect(__('storage.validation.sftp_auth_required'))->not->toBe('storage.validation.sftp_auth_required');
        expect(__('storage.help.plain_ftp_warning'))->not->toBe('storage.help.plain_ftp_warning');
    }

    app()->setLocale('en');
});

it('probes a destination and reports success when write/read/delete all match', function () {
    $dest = makeDestination();

    $response = $this->withHeaders(storageAdminAuthHeader())->postJson("/api/integrations/storage/destinations/{$dest->id}/test");

    $response->assertOk();
    expect($response->json('test.success'))->toBeTrue()
        ->and($response->json('test.message'))->toBe('Connection succeeded.')
        ->and($response->json('test.error_class'))->toBeNull()
        ->and($response->json('test.latency_ms'))->toBeInt();
});

it('classifies a credentials failure as invalid_credentials without echoing the raw exception', function () {
    $dest = makeDestination();

    // Replace the faked disk with one whose get() throws as if the S3 SDK
    // rejected the access key. The closure's return type is left untyped on
    // purpose — declaring `: Filesystem` here forces PHP to type-check the
    // anonymous class against the contract, which fails and turns our test of
    // the classifier into a test of PHP.
    $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
        fn (array $config) => new class
        {
            public function put(string $key, mixed $contents, array $options = []): bool
            {
                return true;
            }

            public function get(string $key): string
            {
                throw new RuntimeException('InvalidAccessKeyId: AKIA-leaked-into-stack.');
            }

            public function delete(string $path): bool
            {
                return true;
            }
        },
    ));

    $response = $this->withHeaders(storageAdminAuthHeader())->postJson("/api/integrations/storage/destinations/{$dest->id}/test");

    $response->assertOk();
    expect($response->json('test.success'))->toBeFalse()
        ->and($response->json('test.error_class'))->toBe('invalid_credentials');

    // The classifier must NOT echo the raw exception text — it can contain
    // signed URLs, AWS request ids, or partial credentials.
    expect($response->getContent())->not->toContain('AKIA-leaked-into-stack');
});

it('classifies an unreachable host as unreachable', function () {
    $dest = makeDestination();

    $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
        fn (array $config) => new class
        {
            public function put(string $key, mixed $contents, array $options = []): bool
            {
                throw new RuntimeException('Could not resolve host s3.invalid.example (DNS timeout).');
            }

            public function get(string $key): string
            {
                return '';
            }

            public function delete(string $path): bool
            {
                return true;
            }
        },
    ));

    $response = $this->withHeaders(storageAdminAuthHeader())->postJson("/api/integrations/storage/destinations/{$dest->id}/test");

    $response->assertOk();
    expect($response->json('test.error_class'))->toBe('unreachable');
});

it('treats a write/read mismatch as a failure (no silent pass)', function () {
    $dest = makeDestination();

    $this->app->bind(StorageConnectionProber::class, fn () => makeProber(
        fn (array $config) => new class
        {
            public function put(string $key, mixed $contents, array $options = []): bool
            {
                return true;
            }

            // A CDN cache, a transparent proxy, or actual disk corruption can
            // all surface here — none of which the panel should ever silently
            // call "success".
            public function get(string $key): string
            {
                return 'something-else';
            }

            public function delete(string $path): bool
            {
                return true;
            }
        },
    ));

    $response = $this->withHeaders(storageAdminAuthHeader())->postJson("/api/integrations/storage/destinations/{$dest->id}/test");

    $response->assertOk();
    expect($response->json('test.success'))->toBeFalse();
});

it('denies listing destinations without the storage permission', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/integrations/storage/destinations')
        ->assertForbidden();
});

it('denies creating a destination with view-only access', function () {
    $user = User::factory()->create();
    grantPermission($user, 'storage', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/integrations/storage/destinations', s3Payload(['name' => 'Should fail']));

    $response->assertForbidden();
    expect(StorageDestination::count())->toBe(0);
});

it('denies testing a destination with view-only access', function () {
    $dest = makeDestination();

    $user = User::factory()->create();
    grantPermission($user, 'storage', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/integrations/storage/destinations/{$dest->id}/test")
        ->assertForbidden();
});

it('allows an authenticated user with the storage manage permission to do everything', function () {
    $user = User::factory()->create();
    grantPermission($user, 'storage', view: true, manage: true);
    $token = $user->createToken('t')->plainTextToken;

    // Listing
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/integrations/storage/destinations')
        ->assertOk();

    // Creating
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/integrations/storage/destinations', s3Payload(['name' => 'Permitted']));
    $response->assertCreated();
    $id = $response->json('storage_destination.id');

    // Patching
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/integrations/storage/destinations/{$id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('storage_destination.name', 'Renamed');

    // Deleting
    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/integrations/storage/destinations/{$id}")
        ->assertNoContent();

    expect(StorageDestination::count())->toBe(0);
});

it('returns a 404 for an unknown destination id', function () {
    $this->withHeaders(storageAdminAuthHeader())
        ->getJson('/api/integrations/storage/destinations/999999')
        ->assertNotFound();
});

/*
 * The tests above all replace the prober's disk builder, so none of them ever
 * execute the driver's config() — which is how `throw => false` shipped and
 * silently turned every real failure into a "bytes did not match" message.
 * These assert the config itself.
 */
it('builds an S3 driver config that surfaces adapter errors instead of swallowing them', function () {
    $dest = makeDestination();
    $captured = null;

    $prober = makeProber(function (array $config) use (&$captured) {
        $captured = $config;

        return test()->fakeDisk;
    });

    $prober->probe($dest);

    // Without this the adapter returns null/false on failure, the probe falls
    // into the read-back comparison, and classify() never runs.
    expect($captured['throw'])->toBeTrue()
        ->and($captured['driver'])->toBe('s3')
        ->and($captured['bucket'])->toBe('backups-prod')
        ->and($captured['root'])->toBe('app1/');
});

it('uses path-style addressing only when a custom endpoint is configured', function () {
    $captured = [];

    $prober = makeProber(function (array $config) use (&$captured) {
        $captured[] = $config;

        return test()->fakeDisk;
    });

    // Custom endpoint (MinIO, Wasabi, B2) — these route through the path.
    $prober->probe(makeDestination(['name' => 'MinIO', 'config' => ['endpoint' => 'https://minio.example.com']]));

    // Empty endpoint *means* AWS, where path-style is deprecated and
    // unsupported for buckets in regions launched after 2019.
    $prober->probe(makeDestination(['name' => 'AWS', 'config' => ['endpoint' => '']]));

    expect($captured[0]['use_path_style_endpoint'])->toBeTrue()
        ->and($captured[1]['use_path_style_endpoint'])->toBeFalse()
        ->and($captured[1]['endpoint'])->toBeNull();
});

/*
 * Unlike the two above, this one drives the *real* adapter rather than a
 * captured config array. Asserting `stream_reads` is present would pass while
 * proving nothing: what matters is that the key survives Laravel's `?? false`
 * read (FilesystemManager::createS3Driver) and reaches the wire as
 * @http.stream. An injected http_handler is the only place that's observable
 * without a bucket.
 */
it('sends GetObject as a streamed request so a large artefact never lands in memory', function () {
    $destination = new StorageDestination([
        'provider' => StorageProvider::S3,
        'prefix' => '',
        'config' => [
            'access_key' => 'k',
            'secret_key' => 's',
            'region' => 'us-east-1',
            'bucket' => 'backups-prod',
            'endpoint' => null,
        ],
    ]);

    $requestOptions = [];

    $config = app(DestinationDisk::class)->config($destination);

    // Passed straight through to the S3Client constructor, so the SDK resolves
    // @http options against it instead of opening a socket.
    $config['http_handler'] = function ($request, array $options) use (&$requestOptions) {
        $requestOptions[] = $options;

        return Create::promiseFor(new Response(200, [], 'artefact-bytes'));
    };

    Storage::build($config)->readStream('app1/restore.tar.gz');

    // False here means Guzzle buffers the whole GetObject body into memory
    // before readStream() returns — DownloadArtifact's stream_copy_to_stream
    // then copies an already-loaded 5+ GB string and OOMs the worker.
    expect($requestOptions[0]['stream'] ?? false)->toBeTrue();
});

/*
 * Destinations hold backup credentials, and creating, changing and deleting
 * them left no trace in the activity log.
 */
it('records creating, updating and deleting a destination, without its credentials', function () {
    $id = $this->withHeaders(storageAdminAuthHeader())
        ->postJson('/api/integrations/storage/destinations', s3Payload())
        ->assertCreated()
        ->json('storage_destination.id');

    $this->withHeaders(storageAdminAuthHeader())
        ->patchJson("/api/integrations/storage/destinations/{$id}", ['name' => 'Renamed'])
        ->assertOk();

    $this->withHeaders(storageAdminAuthHeader())
        ->deleteJson("/api/integrations/storage/destinations/{$id}")
        ->assertSuccessful();

    $rows = ActivityLog::where('type', 'storage_destination')->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($rows->first()->properties)->toBe(['name' => 'New S3', 'provider' => 's3'])
        ->and($rows->last()->properties)->toBe(['name' => 'Renamed'])
        ->and(json_encode($rows->pluck('properties')))->not->toContain('secret_value');
});
