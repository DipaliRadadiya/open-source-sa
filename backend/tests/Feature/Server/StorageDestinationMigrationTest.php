<?php

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The provider migration moves five S3 columns into one encrypted `config`
 * blob, which makes it the rare migration that carries *data* rather than just
 * shape. Everything a test normally sees — a table built from scratch — is
 * exactly the case this cannot go wrong in: the rows that matter are the ones
 * that already existed.
 *
 * So this exercises the fold and the unfold against real rows, and asserts what
 * is on the other side rather than that the migration did not throw. Both
 * directions re-encrypt: `encrypted:array` encrypts the whole document, so a
 * secret handed through as ciphertext would be encrypted twice and every
 * backup would authenticate with a password made of base64 — a failure that
 * surfaces at the next backup rather than here.
 */

/** A fresh instance of the migration under test. `require` re-evaluates; `require_once` would not. */
function providerMigration(): object
{
    return require database_path('migrations/2026_09_14_110000_add_provider_to_storage_destinations_table.php');
}

it('rolls an S3 destination back into the columns it came from, and forward again', function () {
    $destination = StorageDestination::create([
        'name' => 'Work S3',
        'provider' => 's3',
        'config' => [
            'endpoint' => 'https://s3.example.com',
            'region' => 'eu-west-2',
            'bucket' => 'archives',
            'access_key' => 'AKIAEXAMPLE',
            'secret_key' => 'the-secret',
        ],
    ]);

    providerMigration()->down();

    expect(Schema::hasColumn('storage_destinations', 'bucket'))->toBeTrue()
        ->and(Schema::hasColumn('storage_destinations', 'provider'))->toBeFalse();

    $row = DB::table('storage_destinations')->where('id', $destination->id)->first();

    // The plain columns come back as themselves.
    expect($row->endpoint)->toBe('https://s3.example.com')
        ->and($row->region)->toBe('eu-west-2')
        ->and($row->bucket)->toBe('archives');

    // The secrets come back as ciphertext the restored `encrypted` cast can
    // read — not as the plaintext they were inside the blob, and not as the
    // blob's own ciphertext.
    expect(Crypt::decryptString($row->access_key))->toBe('AKIAEXAMPLE')
        ->and(Crypt::decryptString($row->secret_key))->toBe('the-secret');

    providerMigration()->up();

    $restored = StorageDestination::find($destination->id);

    expect($restored->provider->value)->toBe('s3')
        ->and($restored->config)->toBe([
            'endpoint' => 'https://s3.example.com',
            'region' => 'eu-west-2',
            'bucket' => 'archives',
            'access_key' => 'AKIAEXAMPLE',
            'secret_key' => 'the-secret',
        ]);
});

it('keeps every provider clear of the legacy S3 column names, which is what makes the rollback safe', function () {
    /*
     * The rollback skips non-S3 rows explicitly. That `continue` is currently
     * *redundant* — proven by deleting it, which fails nothing: an FTP config
     * holds `host`/`port`/`username`/…, none of which is a legacy S3 column, so
     * the unfold reads `null` for every one of them and writes the same husk it
     * would have skipped.
     *
     * Redundant is not the same as pointless, and the difference is this test.
     * The day a provider arrives with a `bucket` or a `region` — an
     * S3-compatible variant is the obvious candidate — the skip stops being
     * redundant and starts being the only thing preventing that provider's
     * values from being unfolded into S3 columns and read back as an S3
     * destination. This pins the assumption so that day is a red test here
     * rather than a silently mistyped destination there.
     */
    $legacy = ['endpoint', 'region', 'bucket', 'access_key', 'secret_key'];
    $factory = app(StorageDriverFactory::class);

    foreach ($factory->supported() as $provider) {
        if ($provider === StorageProvider::S3) {
            continue;
        }

        $keys = array_map(
            fn (string $rule): string => str_replace('config.', '', $rule),
            array_keys($factory->forProvider($provider)->rules()),
        );

        expect(array_intersect($keys, $legacy))->toBe(
            [],
            "{$provider->value} shares a config key with the legacy S3 columns — the rollback's non-S3 skip is now load-bearing and needs its own test",
        );
    }
});

it('leaves a non-S3 destination visibly empty rather than pretending it is an S3 one', function () {
    $ftp = StorageDestination::create([
        'name' => 'NAS',
        'provider' => 'ftp',
        'config' => ['host' => 'nas.internal', 'port' => 21, 'username' => 'backup', 'password' => 'pw'],
    ]);

    providerMigration()->down();

    $row = DB::table('storage_destinations')->where('id', $ftp->id)->first();

    // The rollback loses this row's configuration either way — S3 columns
    // cannot hold a hostname. The choice on record is to lose it visibly: a
    // husk that obviously needs re-entering, not a malformed S3 destination
    // that looks configured and fails at the next backup.
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('NAS')
        ->and($row->bucket)->toBeNull()
        ->and($row->access_key)->toBeNull()
        ->and($row->secret_key)->toBeNull();
});

it('nulls a credential it cannot decrypt instead of writing the ciphertext through', function () {
    $destination = StorageDestination::create([
        'name' => 'Rotated Key',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    // What a database restored under a different APP_KEY looks like from here:
    // a blob that is not readable with the key this process holds.
    DB::table('storage_destinations')
        ->where('id', $destination->id)
        ->update(['config' => 'not-decryptable-by-this-key']);

    providerMigration()->down();

    $row = DB::table('storage_destinations')->where('id', $destination->id)->first();

    // Null, loudly. Writing the unreadable value through would produce a
    // destination whose secret is a base64 blob, failing at the next backup
    // with an authentication error pointing nowhere near this migration.
    expect($row->access_key)->toBeNull()
        ->and($row->secret_key)->toBeNull()
        ->and($row->bucket)->toBeNull();
});
