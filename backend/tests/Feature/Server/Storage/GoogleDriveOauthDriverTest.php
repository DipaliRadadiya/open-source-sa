<?php

namespace Tests\Feature\Server\Storage;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\Drivers\GoogleDriveOauthDriver;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Google\Client;
use Google\Service\Drive;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Masbug\Flysystem\GoogleDriveAdapter;

const OAUTH_TOKEN_URL = 'oauth2.googleapis.com/token';

function oauthDriver(): GoogleDriveOauthDriver
{
    return app(GoogleDriveOauthDriver::class);
}

/*
 * Built in memory rather than persisted: the assertions are about how the driver reads a
 * destination, and none of it needs a row.
 */
function oauthDestination(array $config = []): StorageDestination
{
    $destination = new StorageDestination;
    $destination->provider = StorageProvider::GoogleDriveOauth;
    $destination->prefix = 'backups';
    $destination->config = array_merge([
        'client_id' => '123-abc.apps.googleusercontent.com',
        'client_secret' => 'secret',
        'refresh_token' => 'rt',
        // A connected destination always has one; preflight checks it still exists.
        'folder_id' => 'FOLDER-1',
    ], $config);

    return $destination;
}

/**
 * The folder half of preflight, answered without touching Drive.
 *
 * Bound before the driver is resolved, because the driver takes the workspace
 * as a constructor dependency.
 */
function fakeFolderCheck(array $result): void
{
    app()->bind(GoogleDriveWorkspace::class, fn () => new class($result) extends GoogleDriveWorkspace
    {
        public function __construct(private array $result) {}

        public function folderExists(string $c, string $s, string $r, string $folderId): array
        {
            return $this->result;
        }
    });
}

function folderPresent(): void
{
    fakeFolderCheck(['ok' => true, 'reason' => null]);
}

it('is resolvable through the factory like every other provider', function () {
    expect(app(StorageDriverFactory::class)->forProvider(StorageProvider::GoogleDriveOauth))
        ->toBeInstanceOf(GoogleDriveOauthDriver::class);
});

// ---------------------------------------------------------------------------
// preflight — the load-bearing one
// ---------------------------------------------------------------------------

/*
 * A stored refresh token was valid once, not proof it still is: an OAuth app left in "Testing"
 * gets its refresh tokens expired by Google after about a week. Only asking Google can tell.
 */
it('refuses a destination whose grant has been revoked', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(oauthDriver()->preflight(oauthDestination()))->toBe('storage.oauth.revoked');
});

it('passes a destination whose grant still works', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh', 'expires_in' => 3599])]);
    folderPresent();

    expect(oauthDriver()->preflight(oauthDestination()))->toBeNull();
});

/*
 * The folder lives in someone's personal Drive and may be deleted without telling the panel.
 * The token still refreshes, so a token-only preflight would pass a destination that fails.
 */
it('refuses a destination whose folder was deleted from the Drive', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh'])]);
    fakeFolderCheck(['ok' => false, 'reason' => 'storage.oauth.folder_missing']);

    expect(oauthDriver()->preflight(oauthDestination()))->toBe('storage.oauth.folder_missing');
});

/*
 * Trashed is worth an API call: Drive still resolves a trashed folder by id, so uploads would
 * succeed into the Trash and be purged about thirty days later.
 */
it('treats a trashed folder as gone', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh'])]);
    fakeFolderCheck(['ok' => false, 'reason' => 'storage.oauth.folder_missing']);

    expect(oauthDriver()->preflight(oauthDestination()))->not->toBeNull();
});

// Connected, but the folder was never recorded: a half-finished connect, not a Drive failure.
it('says "not connected" when no folder was ever stored', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh'])]);

    expect(oauthDriver()->preflight(oauthDestination(['folder_id' => ''])))
        ->toBe('storage.oauth.not_connected');
});

// Not connected is its own answer, not a Google failure for a request we never made.
it('says "not connected" rather than blaming Google before anyone has approved', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'x'])]);

    expect(oauthDriver()->preflight(oauthDestination(['refresh_token' => ''])))
        ->toBe('storage.oauth.not_connected');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Credential handling
// ---------------------------------------------------------------------------

/*
 * The refresh token mints access tokens for as long as the grant lives; leaving it out of
 * `secretKeys()` would store it in clear beside the encrypted client secret.
 */
it('treats the refresh token as a secret, not as configuration', function () {
    expect(oauthDriver()->secretKeys())
        ->toContain('refresh_token')
        ->toContain('client_secret');
});

it('never exposes the refresh token through public config', function () {
    $public = oauthDriver()->publicConfig(oauthDestination(['account_email' => 'me@gmail.com']));

    expect($public)->not->toHaveKey('refresh_token')
        ->and($public)->not->toHaveKey('client_secret')
        // The form still needs "connected" to choose between Connect and the account.
        ->and($public['connected'])->toBeTrue()
        ->and($public['account_email'])->toBe('me@gmail.com');
});

it('reports not-connected through public config without a token', function () {
    expect(oauthDriver()->publicConfig(oauthDestination(['refresh_token' => '']))['connected'])
        ->toBeFalse();
});

// ---------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------

/*
 * A pasted project id or a truncated copy should fail at the form, not at approval time.
 */
it('rejects a client id that is not a Google client id', function () {
    $rule = oauthDriver()->rules()['config.client_id'];

    expect(validator(['config' => ['client_id' => 'my-project-123']], ['config.client_id' => $rule])->fails())
        ->toBeTrue()
        ->and(validator(
            ['config' => ['client_id' => '123-abc.apps.googleusercontent.com']],
            ['config.client_id' => $rule]
        )->fails())->toBeFalse();
});

/*
 * The destination is created before approval and the connect flow writes the token afterwards,
 * so requiring it here would make connecting impossible.
 */
it('does not require a refresh token at creation time', function () {
    $rules = oauthDriver()->rules();

    expect($rules['config.refresh_token'])->toContain('sometimes')
        ->and($rules['config.refresh_token'])->not->toContain('required');
});

// ---------------------------------------------------------------------------
// Disk config
// ---------------------------------------------------------------------------

it('builds a google_oauth disk that throws on failed writes', function () {
    $config = oauthDriver()->config(oauthDestination());

    expect($config['driver'])->toBe('google_oauth')
        // Without this a failed upload returns false and looks like a successful backup.
        ->and($config['throw'])->toBeTrue()
        ->and($config['root'])->toBe('backups')
        ->and($config['refresh_token'])->toBe('rt');
});

/*
 * The provider/status/category guard in StorageDestinationTest does not reach these keys, so they
 * would ship as raw `storage.oauth.*` strings in every locale and nothing would fail.
 */
it('has copy for every OAuth outcome in every locale', function () {
    $keys = [
        'not_connected', 'revoked', 'user_quota', 'folder_missing', 'denied',
        'code_expired', 'bad_client', 'start_failed', 'poll_failed', 'no_refresh_token',
        'folder_failed', 'wrong_provider',
        'wrong_client_type',
    ];

    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach ($keys as $key) {
            expect(__('storage.oauth.'.$key))->not->toBe('storage.oauth.'.$key);
        }
    }
});

/*
 * Each case asserts the *advice*, not the code: "free up space" and "enable an API" send someone
 * to different places. OAuth consent succeeds without the Drive API enabled; the first Drive
 * call is what 403s.
 */
dataset('drive_failures', [
    'api not enabled' => [
        'Google Drive API has not been used in project 123 before or it is disabled.',
        'storage.oauth.api_disabled',
    ],
    'api disabled, other wording' => [
        '{"error":{"status":"PERMISSION_DENIED","message":"accessNotConfigured"}}',
        'storage.oauth.api_disabled',
    ],
    'drive full' => [
        'The user\'s Drive storage quota has been exceeded. storageQuotaExceeded',
        'storage.oauth.user_quota',
    ],
    'scope missing' => [
        'Request had insufficient authentication scopes.',
        'storage.oauth.insufficient_scope',
    ],
    'genuinely unknown' => [
        'Backend Error',
        'storage.oauth.folder_failed',
    ],
]);

it('names the cause Google actually gave', function (string $googleSays, string $expected) {
    // The factory succeeds and create fails; a throwing factory would read as a dead grant.
    $workspace = new GoogleDriveWorkspace(
        fn () => new class($googleSays) extends Drive
        {
            public function __construct(string $message)
            {
                $this->files = new class($message)
                {
                    public function __construct(private string $message) {}

                    public function create($file, $opts = [])
                    {
                        throw new \RuntimeException($this->message);
                    }
                };
            }
        },
    );

    expect($workspace->prepare('c', 's', 'rt', 'Panel backups')['reason'])->toBe($expected);
})->with('drive_failures');

// The classifier only names causes somebody thought of; the log must keep Google's own words.
it('logs what Google said, not only what the panel decided', function () {
    Log::spy();

    $workspace = new GoogleDriveWorkspace(
        fn () => new class extends Drive
        {
            public function __construct()
            {
                $this->files = new class
                {
                    public function create($file, $opts = [])
                    {
                        throw new \RuntimeException('Some future error nobody has classified');
                    }
                };
            }
        },
    );

    $workspace->prepare('c', 's', 'rt', 'Panel backups');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $c['detail'] === 'Some future error nobody has classified'
            && $c['error_class'] === 'storage.oauth.folder_failed');
});

/*
 * A folder id is not a folder name.
 *
 * With `useDisplayPaths` on, the adapter treats its root argument as a display name and creates a
 * directory with that literal text when it finds none. Passing `folder_id` there made a folder
 * named after the id in the user's Drive.
 *
 * Asserted on the adapter's resolved root: what matters is where writes land.
 */
it('roots the disk at the folder id without inventing a folder named after it', function () {
    $adapter = new GoogleDriveAdapter(
        new Drive(new Client),
        null,
        [
            'sharedFolderId' => '1f5v8y369-o8pIvZse_VXpIbgbdY3POXu',
            'useDisplayPaths' => true,
        ],
    );

    $root = (new \ReflectionProperty($adapter, 'root'));
    $root->setAccessible(true);

    expect($root->getValue($adapter))->toBe('1f5v8y369-o8pIvZse_VXpIbgbdY3POXu');
});

/*
 * Pinned regression: an id in the positional root makes the adapter look for a folder *named*
 * that, which is the lookup that creates one.
 */
it('never passes the folder id as the adapter root', function () {
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    $oauthDisk = substr($provider, (int) strpos($provider, "Storage::extend('google_oauth'"));
    $oauthDisk = substr($oauthDisk, 0, (int) strpos($oauthDisk, 'return new FilesystemAdapter'));

    expect($oauthDisk)->toContain('sharedFolderId')
        ->and($oauthDisk)->not->toContain("(string) (\$config['folder_id'] ?? ''),");
});

/*
 * `folderExists` against a fake Drive; the tests above stub it, so the trashed case would
 * otherwise be asserted only against a stub.
 */
function driveReturning(?bool $trashed, ?string $throws = null): GoogleDriveWorkspace
{
    return new GoogleDriveWorkspace(fn () => new class($trashed, $throws) extends Drive
    {
        public function __construct(?bool $trashed, ?string $throws)
        {
            $this->files = new class($trashed, $throws)
            {
                public function __construct(private ?bool $trashed, private ?string $throws) {}

                public function get($id, $opts = [])
                {
                    if ($this->throws !== null) {
                        throw new \RuntimeException($this->throws);
                    }

                    return new class($this->trashed)
                    {
                        public function __construct(private ?bool $trashed) {}

                        public function getTrashed(): ?bool
                        {
                            return $this->trashed;
                        }
                    };
                }
            };
        }
    });
}

it('accepts a folder that is present and not in the trash', function () {
    expect(driveReturning(false)->folderExists('c', 's', 'rt', 'FOLDER-1'))
        ->ok->toBeTrue();
});

// Drive resolves a trashed folder by id; without this uploads would go into the Trash and be purged.
it('calls a trashed folder missing', function () {
    expect(driveReturning(true)->folderExists('c', 's', 'rt', 'FOLDER-1'))
        ->ok->toBeFalse()
        ->reason->toBe('storage.oauth.folder_missing');
});

it('calls a deleted folder missing', function () {
    expect(driveReturning(null, 'File not found: FOLDER-1')->folderExists('c', 's', 'rt', 'FOLDER-1'))
        ->ok->toBeFalse()
        ->reason->toBe('storage.oauth.folder_missing');
});

// No folder recorded is a half-finished connect, and answering it must not cost a request.
it('answers an empty folder id without asking Drive', function () {
    expect(driveReturning(false)->folderExists('c', 's', 'rt', ''))
        ->ok->toBeFalse()
        ->reason->toBe('storage.oauth.not_connected');
});

// ---------------------------------------------------------------------------
// heal — the panel makes another folder rather than demanding re-consent
// ---------------------------------------------------------------------------

/**
 * A workspace that reports the folder gone and records what gets created.
 */
function healingWorkspace(object $seen, bool $canCreate = true): GoogleDriveWorkspace
{
    return new class($seen, $canCreate) extends GoogleDriveWorkspace
    {
        public function __construct(private object $seen, private bool $canCreate)
        {
            $this->seen->name = null;
            $this->seen->created = false;
        }

        public function folderExists(string $c, string $s, string $r, string $folderId): array
        {
            return ['ok' => false, 'reason' => 'storage.oauth.folder_missing'];
        }

        public function prepare(string $c, string $s, string $r, string $name): array
        {
            $this->seen->name = $name;
            $this->seen->created = $this->canCreate;

            return $this->canCreate
                ? ['ok' => true, 'folder_id' => 'FOLDER-NEW', 'account_email' => 'me@gmail.com', 'reason' => null]
                : ['ok' => false, 'folder_id' => null, 'account_email' => null, 'reason' => 'storage.oauth.user_quota'];
        }
    };
}

function persistedOauthDestination(array $config = []): StorageDestination
{
    return StorageDestination::create([
        'name' => 'Drive',
        'provider' => StorageProvider::GoogleDriveOauth,
        'prefix' => '',
        'config' => array_merge([
            'client_id' => '123-abc.apps.googleusercontent.com',
            'client_secret' => 'secret',
            'refresh_token' => 'rt',
            'folder_id' => 'FOLDER-GONE',
        ], $config),
    ]);
}

/*
 * The panel created that folder and still holds a working refresh token, so it can create
 * another; an unattended backup cannot complete a consent screen.
 */
it('makes a new folder when the old one is gone', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen));

    $destination = persistedOauthDestination();
    oauthDriver()->heal($destination);

    expect($destination->fresh()->configValue('folder_id'))->toBe('FOLDER-NEW')
        ->and($seen->name)->toContain('Backups');
});

// The stored verdict was about a folder that no longer exists.
it('clears the failure that was about the folder it just replaced', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen));

    $destination = persistedOauthDestination();
    $destination->forceFill([
        'last_test_success' => false,
        'last_test_error' => 'storage.oauth.folder_missing',
    ])->save();

    oauthDriver()->heal($destination);

    expect($destination->fresh()->last_test_error)->toBeNull();
});

// Nothing to heal with: creating a folder needs a grant, so "not connected" stays the answer.
it('does not try to heal a destination nobody has connected', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen));

    oauthDriver()->heal(persistedOauthDestination(['refresh_token' => '']));

    expect($seen->created)->toBeFalse();
});

/*
 * Full Drive, revoked grant, API off: the destination is left as it was so the triggering
 * operation reports the real failure; a half-healed row would claim a folder never made.
 */
it('leaves the destination alone when it cannot make a folder either', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen, canCreate: false));

    $destination = persistedOauthDestination();
    oauthDriver()->heal($destination);

    expect($destination->fresh()->configValue('folder_id'))->toBe('FOLDER-GONE');
});

// The common case must stay cheap: one metadata call that answers "yes".
it('does not create anything when the folder is still there', function () {
    $seen = new \stdClass;
    $seen->created = false;

    app()->bind(GoogleDriveWorkspace::class, fn () => new class($seen) extends GoogleDriveWorkspace
    {
        public function __construct(private object $seen) {}

        public function folderExists(string $c, string $s, string $r, string $f): array
        {
            return ['ok' => true, 'reason' => null];
        }

        public function prepare(string $c, string $s, string $r, string $n): array
        {
            $this->seen->created = true;

            return ['ok' => true, 'folder_id' => 'SHOULD-NOT-HAPPEN', 'account_email' => null, 'reason' => null];
        }
    });

    $destination = persistedOauthDestination();
    oauthDriver()->heal($destination);

    expect($seen->created)->toBeFalse()
        ->and($destination->fresh()->configValue('folder_id'))->toBe('FOLDER-GONE');
});

/*
 * The wiring, not just the method: `DestinationDisk::for()` is the seam every path goes through,
 * so `heal()` also runs on unattended backups, not only when someone presses Test.
 */
it('repairs the folder when a disk is built for the destination', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen));

    $destination = persistedOauthDestination();

    $disk = new DestinationDisk(
        app(StorageDriverFactory::class),
        // The disk itself is irrelevant here; what is under test is that
        // building one repaired the destination first.
        fn (array $config) => Storage::fake('heal-test'),
    );

    $disk->for($destination);

    expect($destination->fresh()->configValue('folder_id'))->toBe('FOLDER-NEW');
});

/*
 * The repair must happen *before* the config is read, or the triggering operation would still
 * get the dead folder id.
 */
it('builds the disk on the new folder, not the one that was gone', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen));

    $captured = new \stdClass;
    $captured->folderId = null;

    $disk = new DestinationDisk(
        app(StorageDriverFactory::class),
        function (array $config) use ($captured) {
            $captured->folderId = $config['folder_id'] ?? null;

            return Storage::fake('heal-order');
        },
    );

    $disk->for(persistedOauthDestination());

    expect($captured->folderId)->toBe('FOLDER-NEW');
});

/*
 * Test connection must leave nothing behind. A key containing a directory (`.probe/<uuid>.bin`)
 * makes the provider create that directory, and deleting the object does not remove it; on
 * Drive that is a real folder in the user's account.
 */
it('probes with a flat key so no folder is created', function () {
    $source = file_get_contents(
        app_path('Services/Server/Backups/Storage/StorageConnectionProber.php')
    );

    expect($source)->toContain("'.probe-'")
        ->and($source)->not->toContain("'.probe/'");
});
