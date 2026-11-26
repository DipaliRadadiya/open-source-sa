<?php

namespace Tests\Feature\Server\Storage;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use App\Services\Server\Backups\Storage\GoogleOauthState;
use Database\Seeders\PermissionSeeder;
use Google\Service\Drive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

const CONNECT_TOKEN_URL = 'oauth2.googleapis.com/token';

const CALLBACK_URL = '/api/integrations/storage/oauth/callback';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    config(['server.storage.panel_url' => 'https://panel.example.test']);

    $this->destination = StorageDestination::create([
        'name' => 'My Drive',
        'provider' => StorageProvider::GoogleDriveOauth,
        'prefix' => 'backups',
        'config' => [
            'client_id' => '123-abc.apps.googleusercontent.com',
            'client_secret' => 'shh',
        ],
    ]);

    // The workspace is the only part that touches Drive; stub it so no socket opens, while the
    // token exchange still runs against faked HTTP.
    $this->app->bind(GoogleDriveWorkspace::class, fn () => new GoogleDriveWorkspace(
        fn (string $id, string $secret, string $refresh) => new class extends Drive
        {
            public function __construct() {}
        },
    ));
});

function authHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function fakeWorkspace(array $result): void
{
    app()->bind(GoogleDriveWorkspace::class, fn () => new class($result) extends GoogleDriveWorkspace
    {
        public function __construct(private array $result) {}

        public function prepare(string $c, string $s, string $r, string $n): array
        {
            return $this->result;
        }
    });
}

function startUrl(): string
{
    return '/api/integrations/storage/destinations/'.test()->destination->id.'/oauth/start';
}

/** A `state` this panel would really have issued, for the given destination. */
function issuedState(?StorageDestination $destination = null): string
{
    return app(GoogleOauthState::class)->issue($destination ?? test()->destination);
}

function tokenGranted(): void
{
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-live'])]);
    fakeWorkspace(['ok' => true, 'folder_id' => 'FOLDER-1', 'account_email' => 'me@gmail.com', 'reason' => null]);
}

it('hands back a consent URL carrying the scope and an offline grant', function () {
    $response = $this->withHeaders(authHeader())->postJson(startUrl())->assertOk();

    $url = $response->json('oauth.authorize_url');

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($url)->toContain(urlencode('https://www.googleapis.com/auth/drive.file'))
        // Without `offline` Google issues no refresh token; without `consent` it withholds one
        // after the first approval.
        ->and($url)->toContain('access_type=offline')
        ->and($url)->toContain('prompt=consent');
});

/*
 * One source for the redirect URI: it is served with the destinations list, and a second copy
 * would drift on a string Google compares byte for byte.
 */
it('does not repeat the redirect URI on the start call', function () {
    $this->withHeaders(authHeader())->postJson(startUrl())
        ->assertOk()
        ->assertJsonMissingPath('oauth.redirect_uri');
});

/*
 * A relative redirect URI comes back from Google as a generic `invalid_request` that reads like
 * a bad client id. Refuse before asking Google.
 */
it('refuses to start when the panel does not know its own address', function () {
    config(['server.storage.panel_url' => '']);

    $this->withHeaders(authHeader())->postJson(startUrl())->assertStatus(422);

    Http::assertNothingSent();
});

it('keeps the attempt out of the destination row', function () {
    $this->withHeaders(authHeader())->postJson(startUrl())->assertOk();

    expect($this->destination->fresh()->config)->not->toHaveKey('refresh_token')
        ->and(Cache::get("storage:oauth:state:{$this->destination->id}"))->not->toBeNull();
});

it('stores the refresh token and the folder once approved', function () {
    tokenGranted();

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'connected');

    $config = $this->destination->fresh()->config;

    expect($config['refresh_token'])->toBe('rt-live')
        // The panel makes its own folder: `drive.file` only sees what this app created.
        ->and($config['folder_id'])->toBe('FOLDER-1')
        ->and($config['account_email'])->toBe('me@gmail.com');
});

/*
 * `stateless()` removes the session that ties a callback to its request, so the seal is the
 * only binding left. These four tests cover it.
 */
it('refuses a state this panel did not issue', function () {
    tokenGranted();

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => 'not-a-real-state'])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed');

    // Never got as far as spending the code.
    Http::assertNothingSent();
    expect($this->destination->fresh()->config)->not->toHaveKey('refresh_token');
});

// A `state` lifted from history or a referrer still decrypts; the burned nonce is what stops it.
it('refuses a state that has already been used', function () {
    tokenGranted();
    $state = issuedState();

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => $state])
        ->assertJsonPath('oauth.status', 'connected');

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => $state])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed');
});

/*
 * The attack the seal prevents: approve on your own account, then post the callback naming
 * someone else's destination. The id must come from the seal, never the request.
 */
it('connects the destination named in the state, not one named in the request', function () {
    tokenGranted();

    $other = StorageDestination::create([
        'name' => 'Someone elses Drive',
        'provider' => StorageProvider::GoogleDriveOauth,
        'prefix' => '',
        'config' => ['client_id' => 'x-999.apps.googleusercontent.com', 'client_secret' => 'nope'],
    ]);

    $this->withHeaders(authHeader())->postJson(CALLBACK_URL, [
        'code' => 'CODE-1',
        'state' => issuedState($this->destination),
        // Ignored — there is no route parameter and no accepted field for it.
        'storage_destination_id' => $other->id,
    ])->assertOk();

    expect($this->destination->fresh()->config)->toHaveKey('refresh_token')
        ->and($other->fresh()->config)->not->toHaveKey('refresh_token');
});

/*
 * Separates the two halves of the seal: this payload decrypts with the panel's key and only the
 * nonce is wrong. Without the server-side comparison, anything that can encrypt with the app
 * key could name any destination.
 */
it('refuses a sealed state whose nonce was never issued', function () {
    tokenGranted();
    issuedState();

    $forged = Crypt::encryptString((string) json_encode([
        'did' => $this->destination->id,
        'nonce' => 'nonce-we-never-issued',
        'exp' => now()->addMinutes(10)->getTimestamp(),
    ]));

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => $forged])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed');

    Http::assertNothingSent();
    expect($this->destination->fresh()->config)->not->toHaveKey('refresh_token');
});

it('refuses a state whose window has closed', function () {
    tokenGranted();
    $state = issuedState();

    // The seal outlives nothing: the nonce it points at is gone.
    Cache::forget("storage:oauth:state:{$this->destination->id}");

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => $state])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed');
});

/*
 * Google reuses `invalid_grant` for a spent and an aged code; both mean "connect again", not
 * "bad credentials".
 */
it('names a spent code as something to retry, not a bad credential', function () {
    Http::fake([CONNECT_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed')
        ->assertJsonPath('oauth.message', __('storage.oauth.code_expired'));
});

/* The most common setup fault: the URI in Cloud Console does not match what we sent. */
it('names a redirect URI mismatch instead of blaming the client id', function () {
    Http::fake([CONNECT_TOKEN_URL => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'redirect_uri_mismatch',
    ], 400)]);

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertJsonPath('oauth.message', __('storage.oauth.redirect_mismatch'));
});

// Google omits the refresh token when the account already granted this client; storing the rest
// would leave a credential that dies within the hour.
it('refuses an approval that carries no lasting token', function () {
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at'])]);

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertJsonPath('oauth.status', 'failed')
        ->assertJsonPath('oauth.message', __('storage.oauth.no_refresh_token'));

    expect($this->destination->fresh()->config)->not->toHaveKey('refresh_token');
});

/*
 * A working grant but a Drive with no room: storing the token would leave a destination that
 * looks connected and fails every backup.
 */
it('refuses to store a token when the folder cannot be created', function () {
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt'])]);
    fakeWorkspace(['ok' => false, 'folder_id' => null, 'account_email' => null, 'reason' => 'storage.oauth.user_quota']);

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed');

    expect($this->destination->fresh()->config)->not->toHaveKey('refresh_token');
});

it('never returns the refresh token to the browser', function () {
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-secret'])]);
    fakeWorkspace(['ok' => true, 'folder_id' => 'F', 'account_email' => 'me@gmail.com', 'reason' => null]);

    $response = $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertOk();

    expect($response->getContent())->not->toContain('rt-secret')
        ->and($response->getContent())->not->toContain('shh');
});

// The callback stores a credential that can create files in a personal Google account; `view` is
// not enough.
it('refuses a viewer without manage permission', function () {
    $viewer = User::factory()->create();
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson(startUrl())
        ->assertForbidden();
});

it('refuses the callback to a viewer without manage permission', function () {
    $viewer = User::factory()->create();
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson(CALLBACK_URL, ['code' => 'C', 'state' => issuedState()])
        ->assertForbidden();
});

it('refuses a destination that does not use Google sign-in', function () {
    $s3 = StorageDestination::create([
        'name' => 'S3', 'provider' => StorageProvider::S3, 'prefix' => '',
        'config' => ['bucket' => 'b'],
    ]);

    $this->withHeaders(authHeader())
        ->postJson("/api/integrations/storage/destinations/{$s3->id}/oauth/start")
        ->assertStatus(422);
});

/*
 * The callback URL goes into the Google OAuth client when it is created, before any client id or
 * destination exists, so it rides on the list, not on a destination.
 */
it('offers the callback URL before any destination has been made', function () {
    StorageDestination::query()->delete();

    $this->withHeaders(authHeader())
        ->getJson('/api/integrations/storage/destinations')
        ->assertOk()
        ->assertJsonPath('storage_destinations', [])
        ->assertJsonPath(
            'google_oauth_redirect_uri',
            'https://panel.example.test/integrations/storage/oauth/callback',
        );
});

/*
 * The folder lands in a personal Google Drive and must explain itself without being opened. Its
 * name must come from the panel's branding, not `app.name`, which ships as "Laravel".
 */
it('names the folder after the panel, not the framework', function () {
    config(['branding.name' => 'ServerAvatar']);
    tokenGranted();

    // An object: a promoted property cannot be by-reference.
    $seen = new \stdClass;
    $seen->name = null;

    app()->bind(GoogleDriveWorkspace::class, fn () => new class($seen) extends GoogleDriveWorkspace
    {
        public function __construct(private object $seen) {}

        public function prepare(string $c, string $s, string $r, string $name): array
        {
            $this->seen->name = $name;

            return ['ok' => true, 'folder_id' => 'F', 'account_email' => null, 'reason' => null];
        }
    });

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertJsonPath('oauth.status', 'connected');

    expect($seen->name)
        ->toContain('ServerAvatar')
        // Which machine's backups these are: one Drive can hold several panels'.
        ->toContain('panel.example.test')
        ->toContain('My Drive')
        ->not->toContain('Laravel');
});

/*
 * Connecting replaces both the grant and the folder, so a failure verdict recorded against the
 * old pair describes something that no longer exists and must be cleared.
 */
it('clears a failed test verdict when the destination is reconnected', function () {
    tokenGranted();

    $this->destination->forceFill([
        'last_tested_at' => now()->subHour(),
        'last_test_success' => false,
        'last_test_error' => 'storage.oauth.folder_missing',
    ])->save();

    $this->withHeaders(authHeader())
        ->postJson(CALLBACK_URL, ['code' => 'CODE-1', 'state' => issuedState()])
        ->assertJsonPath('oauth.status', 'connected');

    $fresh = $this->destination->fresh();

    expect($fresh->last_test_error)->toBeNull()
        ->and($fresh->last_test_success)->toBeNull()
        // "Not yet tested", not a pass: connecting does not run the probe's write/read-back.
        ->and($fresh->last_tested_at)->toBeNull();
});
