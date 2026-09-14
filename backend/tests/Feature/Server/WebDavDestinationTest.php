<?php

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\SftpHostKey;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * WebDAV destinations: Nextcloud, ownCloud, Synology, plain `mod_dav` — and
 * pCloud, which offers no other protocol.
 *
 * The ask was pCloud specifically. It is implemented as generic WebDAV because
 * pCloud's own documentation says its WebDAV is meant for *small files* with
 * stability that "may have interruptions", and recommends the desktop app for
 * large transfers. A site archive is not a small file. Generalising costs
 * nothing, buys the self-hosted servers this panel's users are more likely to
 * own, and stops one vendor's weakest surface from being the whole feature.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->fakeDisk = Storage::fake();
});

function davHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function davProber(?callable $diskBuilder = null): StorageConnectionProber
{
    return new StorageConnectionProber(
        drivers: app(StorageDriverFactory::class),
        hostKeys: new SftpHostKey(fn () => throw new RuntimeException('SFTP host key reader must not run for WebDAV')),
        diskBuilder: $diskBuilder ?? fn (array $config) => test()->fakeDisk,
    );
}

function makeDavDestination(array $config = [], string $name = 'Nextcloud', ?string $prefix = null): StorageDestination
{
    return StorageDestination::create([
        'name' => $name,
        'provider' => StorageProvider::WebDav,
        'prefix' => $prefix,
        'config' => array_merge([
            'base_uri' => 'https://cloud.example.com/remote.php/dav/files/me/',
            'username' => 'me',
            'password' => 'dav_secret_value',
        ], $config),
    ]);
}

/** A disk whose put() throws whatever the server would have answered. */
function davFailure(string $message): callable
{
    return fn (array $config) => new class($message)
    {
        public function __construct(private string $message) {}

        public function put(string $key, mixed $contents, array $options = []): bool
        {
            throw new RuntimeException($this->message);
        }
    };
}

it('creates a WebDAV destination without returning the password', function () {
    $response = $this->withHeaders(davHeaders())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Nextcloud',
        'provider' => 'webdav',
        'config' => [
            'base_uri' => 'https://cloud.example.com/remote.php/dav/files/me/',
            'username' => 'me',
            'password' => 'dav_secret_value',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('storage_destination.provider', 'webdav')
        ->assertJsonPath('storage_destination.config.base_uri', 'https://cloud.example.com/remote.php/dav/files/me/')
        ->assertJsonPath('storage_destination.has_credentials', true);

    expect($response->json('storage_destination.config'))->not->toHaveKey('password')
        ->and($response->getContent())->not->toContain('dav_secret_value')
        ->and(StorageDestination::first()->getRawOriginal('config'))->not->toContain('dav_secret_value');
});

it('applies the same SSRF guard as the S3 endpoint', function () {
    // The base URI is an operator-supplied address the panel then connects to
    // from inside the network — the same surface, so the same rule, including
    // the noncanonical spellings that walked past it before today.
    foreach ([
        'http://cloud.example.com/dav/',
        'https://127.0.0.1/dav/',
        'https://169.254.169.254/dav/',
        'https://0251.0376.0251.0376/dav/',
        'https://0177.0.0.1/dav/',
    ] as $uri) {
        $this->withHeaders(davHeaders())->postJson('/api/integrations/storage/destinations', [
            'name' => 'Blocked '.$uri,
            'provider' => 'webdav',
            'config' => ['base_uri' => $uri, 'username' => 'u', 'password' => 'p'],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.base_uri');
    }

    expect(StorageDestination::count())->toBe(0);
});

it('normalises the base URI to exactly one trailing slash', function () {
    // Sabre resolves the prefix against the base URI, so a missing slash
    // silently drops the last path segment — the destination then writes one
    // directory up, which is a real, valid, wrong location.
    $withSlash = makeDavDestination(['base_uri' => 'https://cloud.example.com/dav/'], name: 'With slash');
    $without = makeDavDestination(['base_uri' => 'https://cloud.example.com/dav'], name: 'Without slash');

    expect(app(DestinationDisk::class)->config($withSlash)['baseUri'])->toBe('https://cloud.example.com/dav/')
        ->and(app(DestinationDisk::class)->config($without)['baseUri'])->toBe('https://cloud.example.com/dav/');
});

it('puts the destination prefix beneath the base URI', function () {
    $destination = makeDavDestination(prefix: 'shop.example.com');

    expect(app(DestinationDisk::class)->config($destination)['prefix'])->toBe('shop.example.com');
});

it('surfaces adapter errors rather than swallowing them', function () {
    // Same reason as every other driver: with `throw => false` a failed write
    // returns false and a backup that never happened reports success.
    expect(app(DestinationDisk::class)->config(makeDavDestination())['throw'])->toBeTrue();
});

it('reads the HTTP status rather than guessing', function () {
    $cases = [
        '401 Unauthorized' => 'invalid_credentials',
        '403 Forbidden' => 'invalid_credentials',
        '404 Not Found' => 'root_missing',
        // WebDAV's own "insufficient storage". Nextcloud sends it when a quota
        // is full; reporting it as unreachable would send someone to check a
        // network that is working perfectly.
        '507 Insufficient Storage' => 'dav_full',
    ];

    foreach ($cases as $message => $expected) {
        $destination = makeDavDestination(name: 'Case '.$expected.' '.$message);
        $result = davProber(davFailure($message))->probe($destination);

        expect($result['error_class'])->toBe($expected, "«{$message}» should classify as {$expected}");
    }
});

it('names 2FA when the server drops the connection without answering', function () {
    // pCloud with two-factor enabled resets the TCP connection instead of
    // returning a 401, so there is no authentication error to read — just a
    // timeout. "Could not connect" is technically true and sends the operator
    // to check a firewall, so the copy names the actual cause.
    $result = davProber(davFailure('Connection reset by peer'))->probe(makeDavDestination());

    expect($result['error_class'])->toBe('dav_reset');

    app()->setLocale('en');
    expect(__('storage.test.dav_reset'))->toContain('two-factor');
});

it('warns about pCloud in every locale, because the vendor says it is for small files', function () {
    // pCloud's own docs: WebDAV is "best for small files", stability "may have
    // interruptions". A site archive is not a small file, so offering pCloud
    // without saying so would be the panel implying something the vendor does
    // not.
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        expect(__('storage.help.pcloud_warning'))->not->toBe('storage.help.pcloud_warning')
            ->and(trim(__('storage.help.pcloud_warning')))->not->toBe('');
    }

    app()->setLocale('en');
});

it('does not run the SFTP host-key reader for a WebDAV destination', function () {
    // The injected reader throws if touched.
    expect(davProber()->probe(makeDavDestination())['success'])->toBeTrue();
});
