<?php

use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // Tests should never reach a real S3-compatible endpoint. The probe
    // takes a disk-builder closure; we bind a Container-level override
    // here so any ServiceProvider-level singleton resolves to the same
    // fake too. The default closure inside the prober is replaced for
    // every test — including ones that don't reach the test endpoint.
    $this->fakeDisk = Storage::fake();

    $this->app->bind(StorageConnectionProber::class, fn () => new StorageConnectionProber(
        // Return type is deliberately untyped. A typed `: Filesystem`
        // here would force the resolved `$this->fakeDisk` (a
        // LocalFilesystemAdapter returned by Storage::fake()) through
        // PHP's covariant return-type check, which turns the test into
        // a test of PHP's type system rather than the prober.
        diskBuilder: fn (array $config) => $this->fakeDisk,
    ));
});

function storageAdminAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/** A saved destination with the bare minimum fields plus optional overrides. */
function makeDestination(array $overrides = []): StorageDestination
{
    return StorageDestination::create(array_merge([
        'name' => 'Work S3',
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'bucket' => 'backups-prod',
        'prefix' => 'app1/',
        'access_key' => 'AKIA_secret_value',
        'secret_key' => 'shhhh_secret_value',
    ], $overrides));
}

it('lists destinations sorted by name and never returns secrets', function () {
    makeDestination(['name' => 'B-secondary']);
    makeDestination(['name' => 'A-primary']);

    $response = $this->withHeaders(storageAdminAuthHeader())->getJson('/api/integrations/storage/destinations');

    $response->assertOk();
    expect($response->json('storage_destinations'))->toHaveCount(2);
    expect(collect($response->json('storage_destinations'))->pluck('name')->all())
        ->toBe(['A-primary', 'B-secondary']);

    // Defence in depth — scan the whole envelope for the secret values.
    $json = $response->getContent();
    expect($json)->not->toContain('AKIA_secret_value')
        ->and($json)->not->toContain('shhhh_secret_value');
});

it('shows one destination and never returns secrets', function () {
    $dest = makeDestination();

    $response = $this->withHeaders(storageAdminAuthHeader())->getJson("/api/integrations/storage/destinations/{$dest->id}");

    $response->assertOk();
    expect($response->json('storage_destination.driver'))->toBe('s3');
    expect($response->json('storage_destination.has_credentials'))->toBeTrue();
    expect($response->json())->not->toHaveKey('access_key')
        ->and($response->json())->not->toHaveKey('secret_key')
        ->and($response->getContent())->not->toContain('AKIA_secret_value')
        ->and($response->getContent())->not->toContain('shhhh_secret_value');
});

it('creates a destination and stores the credentials encrypted', function () {
    $response = $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'New S3',
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'bucket' => 'backups-prod',
        'prefix' => 'app1/',
        'access_key' => 'AKIA_secret_value',
        'secret_key' => 'shhhh_secret_value',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('storage_destination.name', 'New S3');
    $response->assertJsonPath('storage_destination.has_credentials', true);
    expect($response->json('storage_destination'))->not->toHaveKey('access_key')
        ->and($response->json('storage_destination'))->not->toHaveKey('secret_key');

    $dest = StorageDestination::first();
    // The encrypted cast makes the model access return plaintext; the
    // *raw* (stored) value must not equal it.
    expect($dest->access_key)->toBe('AKIA_secret_value')
        ->and($dest->secret_key)->toBe('shhhh_secret_value')
        ->and($dest->getRawOriginal('access_key'))->not->toBe('AKIA_secret_value')
        ->and($dest->getRawOriginal('secret_key'))->not->toBe('shhhh_secret_value');
});

it('defaults the region to us-east-1 when omitted', function () {
    $response = $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Regionless',
        'bucket' => 'b',
        'access_key' => 'a',
        'secret_key' => 's',
    ]);

    $response->assertCreated();
    expect(StorageDestination::first()->region)->toBe('us-east-1');
});

it('rejects a duplicate name', function () {
    makeDestination(['name' => 'Work S3']);

    $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Work S3',
        'bucket' => 'b',
        'access_key' => 'a',
        'secret_key' => 's',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('rejects an invalid endpoint hostname', function () {
    foreach (['http://s3.example.com', 'https://127.0.0.1', 'https://169.254.169.254', 'https://localhost'] as $endpoint) {
        $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
            'name' => 'Bad endpoint '.$endpoint,
            'endpoint' => $endpoint,
            'bucket' => 'b',
            'access_key' => 'a',
            'secret_key' => 's',
        ])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }

    expect(StorageDestination::count())->toBe(0);
});

it('rejects an invalid bucket name', function () {
    $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Bad bucket',
        'bucket' => 'with spaces and slashes/',
        'access_key' => 'a',
        'secret_key' => 's',
    ])->assertUnprocessable()->assertJsonValidationErrors('bucket');

    expect(StorageDestination::count())->toBe(0);
});

it('rejects a single-line violation on the name', function () {
    $this->withHeaders(storageAdminAuthHeader())->postJson('/api/integrations/storage/destinations', [
        'name' => "Line1\nLine2",
        'bucket' => 'b',
        'access_key' => 'a',
        'secret_key' => 's',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('updates a destination and keeps the secret columns when not sent', function () {
    $dest = makeDestination(['name' => 'Old name']);

    $response = $this->withHeaders(storageAdminAuthHeader())->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
        'name' => 'New name',
    ]);

    $response->assertOk();
    $response->assertJsonPath('storage_destination.name', 'New name');

    // Rename only — credentials should still match what we created with.
    $dest->refresh();
    expect($dest->name)->toBe('New name')
        ->and($dest->access_key)->toBe('AKIA_secret_value')
        ->and($dest->secret_key)->toBe('shhhh_secret_value');
});

it('rotates the secret columns when new values are sent', function () {
    $dest = makeDestination();

    $this->withHeaders(storageAdminAuthHeader())->patchJson("/api/integrations/storage/destinations/{$dest->id}", [
        'access_key' => 'AKIA_rotated',
        'secret_key' => 'shhhh_rotated',
    ])->assertOk();

    $dest->refresh();
    expect($dest->access_key)->toBe('AKIA_rotated')
        ->and($dest->secret_key)->toBe('shhhh_rotated')
        ->and($dest->getRawOriginal('access_key'))->not->toBe('AKIA_rotated')
        ->and($dest->getRawOriginal('secret_key'))->not->toBe('shhhh_rotated');
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

// P2 (backup targets) adds the "refuses to delete a destination still
// referenced by a backup target" case alongside the in-use guard in
// DeleteStorageDestination.

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

    // Replace the faked disk with one whose get() throws as if the S3
    // SDK rejected the access key. The closure's return type is left
    // untyped on purpose — declaring `: Filesystem` here forces PHP to
    // type-check the anonymous class against the contract, which fails
    // and turns our test of the prober's classifier into a test of PHP.
    $this->app->bind(StorageConnectionProber::class, fn () => new StorageConnectionProber(
        diskBuilder: function () {
            return new class
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
            };
        }
    ));

    $response = $this->withHeaders(storageAdminAuthHeader())->postJson("/api/integrations/storage/destinations/{$dest->id}/test");

    $response->assertOk();
    expect($response->json('test.success'))->toBeFalse()
        ->and($response->json('test.error_class'))->toBe('invalid_credentials');

    // The classifier must NOT echo the raw exception text — it can
    // contain signed URLs, AWS request ids, or partial credentials.
    expect($response->getContent())->not->toContain('AKIA-leaked-into-stack');
});

it('classifies an unreachable host as unreachable', function () {
    $dest = makeDestination();

    $this->app->bind(StorageConnectionProber::class, fn () => new StorageConnectionProber(
        diskBuilder: function () {
            return new class
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
            };
        }
    ));

    $response = $this->withHeaders(storageAdminAuthHeader())->postJson("/api/integrations/storage/destinations/{$dest->id}/test");

    $response->assertOk();
    expect($response->json('test.error_class'))->toBe('unreachable');
});

it('treats a write/read mismatch as a failure (no silent pass)', function () {
    $dest = makeDestination();

    $this->app->bind(StorageConnectionProber::class, fn () => new StorageConnectionProber(
        diskBuilder: function () {
            return new class
            {
                public function put(string $key, mixed $contents, array $options = []): bool
                {
                    return true;
                }

                // A CDN cache, a transparent proxy, or actual disk
                // corruption can all surface here — none of which the
                // panel should ever silently call "success".
                public function get(string $key): string
                {
                    return 'something-else';
                }

                public function delete(string $path): bool
                {
                    return true;
                }
            };
        }
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
        ->postJson('/api/integrations/storage/destinations', [
            'name' => 'Should fail',
            'bucket' => 'b',
            'access_key' => 'a',
            'secret_key' => 's',
        ]);

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
        ->postJson('/api/integrations/storage/destinations', [
            'name' => 'Permitted',
            'bucket' => 'b',
            'access_key' => 'a',
            'secret_key' => 's',
        ]);
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
 * The tests above all replace the prober's disk builder, so none of them
 * ever execute driverConfig() — which is how `throw => false` shipped and
 * silently turned every real failure into a "bytes did not match" message.
 * These two assert the config itself.
 */
it('builds an S3 driver config that surfaces adapter errors instead of swallowing them', function () {
    $dest = makeDestination();
    $captured = null;

    $prober = new StorageConnectionProber(
        diskBuilder: function (array $config) use (&$captured) {
            $captured = $config;

            return test()->fakeDisk;
        },
    );

    $prober->probe($dest);

    // Without this the adapter returns null/false on failure, the probe
    // falls into the read-back comparison, and classify() never runs.
    expect($captured['throw'])->toBeTrue()
        ->and($captured['driver'])->toBe('s3')
        ->and($captured['bucket'])->toBe('backups-prod')
        ->and($captured['root'])->toBe('app1/');
});

it('uses path-style addressing only when a custom endpoint is configured', function () {
    $captured = [];

    $prober = new StorageConnectionProber(
        diskBuilder: function (array $config) use (&$captured) {
            $captured[] = $config;

            return test()->fakeDisk;
        },
    );

    // Custom endpoint (MinIO, Wasabi, B2) — these route through the path.
    $prober->probe(makeDestination(['name' => 'MinIO', 'endpoint' => 'https://minio.example.com']));

    // Empty endpoint *means* AWS, where path-style is deprecated and
    // unsupported for buckets in regions launched after 2019.
    $prober->probe(makeDestination(['name' => 'AWS', 'endpoint' => '']));

    expect($captured[0]['use_path_style_endpoint'])->toBeTrue()
        ->and($captured[1]['use_path_style_endpoint'])->toBeFalse()
        ->and($captured[1]['endpoint'])->toBeNull();
});
