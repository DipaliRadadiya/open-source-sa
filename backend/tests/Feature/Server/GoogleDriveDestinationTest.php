<?php

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\GoogleDriveFolder;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use App\Services\Server\Backups\Storage\SftpHostKey;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Google Drive destinations, authenticated by a service account.
 *
 * What matters here is the **refusal**. A service account has no Drive quota of its own, so a
 * personal-Drive folder can accept a small probe file and reject a real archive. `preflight()`
 * asks the API where the folder lives instead of inferring it from a write; these tests pin
 * that it runs *before* anything is written and that its answer is believed.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->fakeDisk = Storage::fake();
});

function driveHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/** A service-account key file, shaped like a real one. */
function serviceAccountJson(string $email = 'panel-backups@demo.iam.gserviceaccount.com'): string
{
    return json_encode([
        'type' => 'service_account',
        'project_id' => 'demo',
        'private_key_id' => 'abc123',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nDRIVE_KEY_SECRET_VALUE\n-----END PRIVATE KEY-----\n",
        'client_email' => $email,
        'client_id' => '1234567890',
    ], JSON_THROW_ON_ERROR);
}

/**
 * A Drive service whose `files->get()` answers with the metadata the test wants, or throws.
 *
 * `driveId` present means a Shared Drive; absent means "My Drive".
 */
function fakeDriveFolder(?string $driveId, string $mimeType = 'application/vnd.google-apps.folder', ?Throwable $throws = null): GoogleDriveFolder
{
    return new GoogleDriveFolder(fn (string $json) => new class($driveId, $mimeType, $throws)
    {
        public object $files;

        public function __construct(?string $driveId, string $mimeType, ?Throwable $throws)
        {
            $this->files = new class($driveId, $mimeType, $throws)
            {
                public function __construct(
                    private ?string $driveId,
                    private string $mimeType,
                    private ?Throwable $throws,
                ) {}

                public function get(string $id, array $params = []): object
                {
                    if ($this->throws !== null) {
                        throw $this->throws;
                    }

                    // The real API omits `driveId` unless asked to support Shared Drives.
                    expect($params['supportsAllDrives'] ?? false)->toBeTrue();

                    return new class($this->driveId, $this->mimeType)
                    {
                        public function __construct(private ?string $driveId, private string $mimeType) {}

                        public function getDriveId(): ?string
                        {
                            return $this->driveId;
                        }

                        public function getMimeType(): string
                        {
                            return $this->mimeType;
                        }

                        public function getName(): string
                        {
                            return 'Company Backups';
                        }
                    };
                }
            };
        }
    });
}

function driveProber(GoogleDriveFolder $folders, ?callable $diskBuilder = null): StorageConnectionProber
{
    // Bound in the container: the driver resolves its own collaborator.
    app()->instance(GoogleDriveFolder::class, $folders);

    return new StorageConnectionProber(
        drivers: app(StorageDriverFactory::class),
        hostKeys: new SftpHostKey(fn () => throw new RuntimeException('SFTP host key reader must not run for Drive')),
        diskBuilder: $diskBuilder ?? fn (array $config) => test()->fakeDisk,
    );
}

function makeDriveDestination(array $config = [], string $name = 'Drive Backups'): StorageDestination
{
    return StorageDestination::create([
        'name' => $name,
        'provider' => StorageProvider::GoogleDrive,
        'prefix' => null,
        'config' => array_merge([
            'service_account_json' => serviceAccountJson(),
            'folder_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz',
        ], $config),
    ]);
}

it('refuses a folder on a personal Drive, because a service account has no quota there', function () {
    $destination = makeDriveDestination();
    $written = false;

    $prober = driveProber(
        // No driveId: the folder is in My Drive.
        fakeDriveFolder(driveId: null),
        function (array $config) use (&$written) {
            $written = true;

            return test()->fakeDisk;
        },
    );

    $result = $prober->probe($destination);

    expect($result['success'])->toBeFalse()
        ->and($result['error_class'])->toBe('drive_personal');

    // The refusal has to come *before* the write: a small probe file can be accepted where a
    // 4 GB archive is not.
    expect($written)->toBeFalse();
});

it('accepts a folder in a Shared Drive and records which one', function () {
    $destination = makeDriveDestination();

    $result = driveProber(fakeDriveFolder(driveId: '0ABCdEfGhIjKlMnOpQr'))->probe($destination);

    expect($result['success'])->toBeTrue();

    $destination->refresh();

    // Recorded so the row can name the Shared Drive and show the address the folder must be
    // shared with.
    expect($destination->configValue('drive_name'))->toBe('Company Backups')
        ->and($destination->configValue('client_email'))->toBe('panel-backups@demo.iam.gserviceaccount.com');
});

it('refuses an id that points at a file rather than a folder', function () {
    // A wrong link (e.g. a spreadsheet) must be refused up front, not fail later.
    $result = driveProber(
        fakeDriveFolder(driveId: '0ABC', mimeType: 'application/vnd.google-apps.spreadsheet'),
    )->probe(makeDriveDestination());

    expect($result['error_class'])->toBe('drive_not_a_folder');
});

it('separates a missing folder from one it simply cannot see', function () {
    $missing = driveProber(
        fakeDriveFolder(null, throws: new RuntimeException('{"error":{"code":404,"message":"File not found: abc"}}')),
    )->probe(makeDriveDestination(name: 'Missing folder'));

    // A wrong id and "never shared with the service account" are different mistakes with
    // different fixes.
    $forbidden = driveProber(
        fakeDriveFolder(null, throws: new RuntimeException('{"error":{"code":403,"message":"Forbidden"}}')),
    )->probe(makeDriveDestination(name: 'Unshared folder'));

    expect($missing['error_class'])->toBe('drive_folder_missing')
        ->and($forbidden['error_class'])->toBe('drive_not_shared');
});

it('says the key is unreadable rather than blaming the connection', function () {
    $destination = makeDriveDestination(['service_account_json' => 'not json at all']);

    $result = driveProber(
        new GoogleDriveFolder(fn (string $json) => throw new JsonException('Syntax error')),
    )->probe($destination);

    expect($result['error_class'])->toBe('drive_bad_key');
});

it('creates a Drive destination without ever returning the key', function () {
    $response = $this->withHeaders(driveHeaders())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Drive Backups',
        'provider' => 'google_drive',
        'config' => [
            'service_account_json' => serviceAccountJson(),
            'folder_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('storage_destination.provider', 'google_drive')
        ->assertJsonPath('storage_destination.config.folder_id', '1AbCdEfGhIjKlMnOpQrStUvWxYz')
        ->assertJsonPath('storage_destination.has_credentials', true);

    expect($response->json('storage_destination.config'))->not->toHaveKey('service_account_json')
        ->and($response->getContent())->not->toContain('DRIVE_KEY_SECRET_VALUE');

    // Encrypted at rest, like every other provider's credentials.
    expect(StorageDestination::first()->getRawOriginal('config'))->not->toContain('DRIVE_KEY_SECRET_VALUE');
});

it('refuses a pasted folder URL, which is the mistake people actually make', function () {
    // The id is the part after /folders/: storing the whole link would fail at the first backup.
    $this->withHeaders(driveHeaders())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Pasted URL',
        'provider' => 'google_drive',
        'config' => [
            'service_account_json' => serviceAccountJson(),
            'folder_id' => 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQr',
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('config.folder_id');

    expect(StorageDestination::count())->toBe(0);
});

it('requires both the key and the folder', function () {
    $this->withHeaders(driveHeaders())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Keyless',
        'provider' => 'google_drive',
        'config' => ['folder_id' => '1AbCdEfGhIjKlMnOpQr'],
    ])->assertUnprocessable()->assertJsonValidationErrors('config.service_account_json');

    $this->withHeaders(driveHeaders())->postJson('/api/integrations/storage/destinations', [
        'name' => 'Folderless',
        'provider' => 'google_drive',
        'config' => ['service_account_json' => serviceAccountJson()],
    ])->assertUnprocessable()->assertJsonValidationErrors('config.folder_id');
});

it('reports a quota refusal as a quota refusal, not as bad credentials', function () {
    // What a personal Drive answers when the preflight was bypassed (e.g. the folder moved
    // later). The key is valid and the host reachable, so neither obvious category fits.
    $result = driveProber(
        fakeDriveFolder(driveId: '0ABC'),
        fn (array $config) => new class
        {
            public function put(string $key, mixed $contents, array $options = []): bool
            {
                throw new RuntimeException('{"error":{"errors":[{"reason":"storageQuotaExceeded"}]}}');
            }
        },
    )->probe(makeDriveDestination());

    expect($result['error_class'])->toBe('drive_quota');
});

it('does not run the SFTP host-key reader for a Drive destination', function () {
    // The fake throws if touched: pins that the per-provider steps stay per-provider.
    $result = driveProber(fakeDriveFolder(driveId: '0ABC'))->probe(makeDriveDestination());

    expect($result['success'])->toBeTrue();
});

/*
| What the folder in somebody's personal Drive is called.
|
| A hardcoded vendor-name fallback would create a folder named after the vendor in a
| reseller customer's own Drive. The brand belongs in config/branding.php, which
| WhiteLabelTest exempts; anywhere else it is a leak.
*/

it('names the folder from configurable branding, not a literal', function () {
    config([
        'branding.name' => 'Acme Cloud',
        'server.storage.panel_url' => 'https://panel.acme.test',
    ]);

    $destination = StorageDestination::create([
        'name' => 'Nightly',
        'provider' => StorageProvider::GoogleDrive->value,
        'config' => ['folder_id' => 'abc', 'service_account' => '{}'],
    ]);

    expect(app(GoogleDriveWorkspace::class)->folderName($destination))
        ->toBe('Acme Cloud Backups (panel.acme.test) — Nightly');
});

it('takes the folder name straight from branding, with no second opinion', function () {
    // config/branding.php resolves the name once and never returns empty, so the value is used
    // directly: a fallback could only disagree with it.
    config([
        'branding.name' => 'Reseller One',
        'server.storage.panel_url' => 'https://panel.acme.test',
    ]);

    $destination = StorageDestination::create([
        'name' => 'Nightly',
        'provider' => StorageProvider::GoogleDrive->value,
        'config' => ['folder_id' => 'abc', 'service_account' => '{}'],
    ]);

    $name = app(GoogleDriveWorkspace::class)->folderName($destination);

    expect($name)->toBe('Reseller One Backups (panel.acme.test) — Nightly')
        ->and($name)->not->toContain('Server'.'Avatar');
});
