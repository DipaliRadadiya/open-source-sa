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
 * Built in memory rather than persisted: every assertion here is about how the
 * driver reads a destination, and none of it needs a row. `StorageDestination`
 * has no factory, and adding one to test a pure reader would be inventing
 * fixtures for their own sake.
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
        // A connected destination always has one; preflight now asks whether
        // it is still there.
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
 * A stored refresh token is a string that was valid once, not evidence that it
 * still is. The likeliest production failure is an OAuth app left in "Testing",
 * where Google expires refresh tokens after about a week: the destination works
 * all week, then every backup fails and nothing in the panel changed. Only
 * asking Google can tell the difference.
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
 * The folder is in somebody's *personal* Drive and they may delete it without
 * telling the panel. The token still refreshes perfectly afterwards, so a
 * token-only preflight put a green tick on exactly the destination whose next
 * run fails — and the first thing to notice would have been a scheduled backup
 * at 3am.
 */
it('refuses a destination whose folder was deleted from the Drive', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh'])]);
    fakeFolderCheck(['ok' => false, 'reason' => 'storage.oauth.folder_missing']);

    expect(oauthDriver()->preflight(oauthDestination()))->toBe('storage.oauth.folder_missing');
});

/*
 * Trashed is the case worth paying an API call for. Drive still resolves a
 * trashed folder by id, so uploads would keep succeeding into the Trash and be
 * purged about thirty days later — a destination reporting success while its
 * archives quietly expire, which nobody discovers until the day they are
 * needed.
 */
it('treats a trashed folder as gone', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh'])]);
    fakeFolderCheck(['ok' => false, 'reason' => 'storage.oauth.folder_missing']);

    expect(oauthDriver()->preflight(oauthDestination()))->not->toBeNull();
});

// Connected, but the folder was never recorded — a connect that half-finished.
// Its own answer, not a Drive failure.
it('says "not connected" when no folder was ever stored', function () {
    Http::fake([OAUTH_TOKEN_URL => Http::response(['access_token' => 'fresh'])]);

    expect(oauthDriver()->preflight(oauthDestination(['folder_id' => ''])))
        ->toBe('storage.oauth.not_connected');
});

// Not connected is its own answer. Reporting Google's failure for a request we
// never made would send the operator to debug a grant that does not exist yet.
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
 * The refresh token mints access tokens on demand for as long as the grant
 * lives. Leaving it out of `secretKeys()` would encrypt the client secret and
 * store the more powerful credential beside it in clear.
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
        // Connected-ness still has to be answerable, or the form cannot choose
        // between a Connect button and a connected account.
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
 * A pasted project id or a truncated copy fails at the form rather than at
 * approval time, when the operator has already walked to their phone.
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
 * The destination is created before anyone has approved anything — the connect
 * flow writes the token afterwards. Requiring it here would make connecting
 * impossible, which is the kind of rule that looks careful and blocks the
 * feature.
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
        // Without this a failed upload returns false and a backup that never
        // happened is indistinguishable from one that did.
        ->and($config['throw'])->toBeTrue()
        ->and($config['root'])->toBe('backups')
        ->and($config['refresh_token'])->toBe('rt');
});

/*
 * The existing provider/status/category guard in StorageDestinationTest does
 * not reach these keys, so they would ship as raw `storage.oauth.*` strings in
 * seven languages and nothing would fail. Every one of them is shown at the
 * moment something has already gone wrong, which is the worst moment to hand
 * someone a translation key.
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
 * The first real user of this feature hit `folder_failed` and was told to check
 * their Drive had space. It had plenty. The cause was the Drive API not being
 * enabled in the Cloud project — invisible until this exact moment, because
 * OAuth is a different service: consent succeeds, a refresh token is issued,
 * and then the first Drive call 403s.
 *
 * Each case asserts the *advice*, not the code: "free up space" and "enable an
 * API" send someone to completely different places, and the old catch-all sent
 * everyone to the wrong one.
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
    // A factory that succeeds and a create that fails — the factory throwing
    // would be read as a dead grant and never reach the classifier.
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

// The classifier can only name causes somebody thought of. Losing Google's own
// words is what made the first real failure of this feature a guess.
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
 * With `useDisplayPaths` on, the adapter treats its root argument as a display
 * name and creates a directory with that literal text when it cannot find one.
 * Passing `folder_id` there therefore made a folder actually called
 * `1f5v8y369-o8pIvZse_VXpIbgbdY3POXu` in a personal Drive, next to the properly
 * named one the panel had already created — reported, reasonably, as a
 * suspected compromise.
 *
 * Asserted on the adapter's resolved root rather than on a mock's arguments,
 * because the thing that matters is where writes land, not which parameter we
 * happened to pass.
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
 * The shape that caused it, pinned so it cannot come back: an id in the
 * positional root makes the adapter go looking for a folder *named* that, which
 * is the lookup that creates one.
 */
it('never passes the folder id as the adapter root', function () {
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    $oauthDisk = substr($provider, (int) strpos($provider, "Storage::extend('google_oauth'"));
    $oauthDisk = substr($oauthDisk, 0, (int) strpos($oauthDisk, 'return new FilesystemAdapter'));

    expect($oauthDisk)->toContain('sharedFolderId')
        ->and($oauthDisk)->not->toContain("(string) (\$config['folder_id'] ?? ''),");
});

/*
 * `folderExists` itself, against a fake Drive — the tests above stub it, so
 * without these the trashed case would be asserted only against my own stub.
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

// Drive resolves a trashed folder by id perfectly well. Without this the panel
// would upload into the Trash and lose it on Google's thirty-day purge.
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

// No folder recorded is a half-finished connect, not a Drive failure — and it
// must not cost a request to say so.
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
 * The panel created that folder and still holds a working refresh token, so it
 * can create another. Requiring a browser round trip to replace it left the
 * destination stuck — and a backup at 3am cannot complete a consent screen.
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

// Nothing to heal with. Creating a folder needs a grant, and inventing one is
// not on the table — "not connected" stays the honest answer.
it('does not try to heal a destination nobody has connected', function () {
    $seen = new \stdClass;
    app()->bind(GoogleDriveWorkspace::class, fn () => healingWorkspace($seen));

    oauthDriver()->heal(persistedOauthDestination(['refresh_token' => '']));

    expect($seen->created)->toBeFalse();
});

/*
 * A full Drive, a revoked grant, the API switched off. The destination is left
 * exactly as it was so the operation that triggered this reports the real
 * failure — a half-healed row would claim a folder that was never made.
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
 * The wiring, not just the method.
 *
 * `heal()` is only useful if something calls it, and the whole point is that it
 * runs on an unattended 3am backup — not merely when somebody presses Test.
 * `DestinationDisk::for()` is the seam every path goes through, so that single
 * line is the feature. Deleting it broke no test until this one existed.
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
 * And it must be repaired *before* the config is read, or the very operation
 * that triggered the repair would still be handed the dead folder id.
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
