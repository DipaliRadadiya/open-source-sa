<?php

namespace Tests\Feature\Server\Storage;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use Database\Seeders\PermissionSeeder;
use Google\Service\Drive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const CONNECT_DEVICE_URL = 'oauth2.googleapis.com/device/code';
const CONNECT_TOKEN_URL = 'oauth2.googleapis.com/token';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->destination = StorageDestination::create([
        'name' => 'My Drive',
        'provider' => StorageProvider::GoogleDriveOauth,
        'prefix' => 'backups',
        'config' => [
            'client_id' => '123-abc.apps.googleusercontent.com',
            'client_secret' => 'shh',
        ],
    ]);

    // The workspace is the only part that would touch Drive itself. Swapped
    // for a recorder so the suite never opens a socket, while the device flow
    // above it still runs for real against faked HTTP.
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

function pollUrl(): string
{
    return '/api/integrations/storage/destinations/'.test()->destination->id.'/oauth/poll';
}

it('hands back a code and the URL to approve it at', function () {
    Http::fake([CONNECT_DEVICE_URL => Http::response([
        'device_code' => 'DEV-1', 'user_code' => 'HXTR-9F2K',
        'verification_url' => 'https://www.google.com/device', 'interval' => 5, 'expires_in' => 1800,
    ])]);

    $this->withHeaders(authHeader())->postJson(startUrl())
        ->assertOk()
        ->assertJsonPath('oauth.user_code', 'HXTR-9F2K')
        ->assertJsonPath('oauth.verification_url', 'https://www.google.com/device');
});

/*
 * The code belongs in the cache, not in a column. It is worthless after half an
 * hour, most attempts are abandoned (people close the tab), and a destination
 * with a working token must not be altered by somebody merely *starting* a
 * reconnection.
 */
it('keeps the device code out of the destination row', function () {
    Http::fake([CONNECT_DEVICE_URL => Http::response(['device_code' => 'DEV-1', 'user_code' => 'U'])]);

    $this->withHeaders(authHeader())->postJson(startUrl())->assertOk();

    expect($this->destination->fresh()->config)->not->toHaveKey('device_code')
        ->and(Cache::get("storage:oauth:device:{$this->destination->id}"))->toBe('DEV-1');
});

it('reports pending while nobody has approved', function () {
    Cache::put("storage:oauth:device:{$this->destination->id}", 'DEV-1', now()->addMinutes(30));
    Http::fake([CONNECT_TOKEN_URL => Http::response(['error' => 'authorization_pending'], 428)]);

    $this->withHeaders(authHeader())->postJson(pollUrl())
        ->assertOk()
        ->assertJsonPath('oauth.status', 'pending');
});

it('stores the refresh token and the folder once approved', function () {
    Cache::put("storage:oauth:device:{$this->destination->id}", 'DEV-1', now()->addMinutes(30));
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-live'])]);
    fakeWorkspace(['ok' => true, 'folder_id' => 'FOLDER-1', 'account_email' => 'me@gmail.com', 'reason' => null]);

    $this->withHeaders(authHeader())->postJson(pollUrl())
        ->assertOk()
        ->assertJsonPath('oauth.status', 'approved');

    $config = $this->destination->fresh()->config;

    expect($config['refresh_token'])->toBe('rt-live')
        // The panel makes its own folder, because `drive.file` can only see
        // what this app created. That is what removes the folder-id field.
        ->and($config['folder_id'])->toBe('FOLDER-1')
        ->and($config['account_email'])->toBe('me@gmail.com');
});

// The code is single-use. Leaving it behind would have the next poll re-ask
// Google about something it has already answered.
it('clears the device code after a successful connection', function () {
    Cache::put("storage:oauth:device:{$this->destination->id}", 'DEV-1', now()->addMinutes(30));
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt'])]);
    fakeWorkspace(['ok' => true, 'folder_id' => 'F', 'account_email' => null, 'reason' => null]);

    $this->withHeaders(authHeader())->postJson(pollUrl())->assertOk();

    expect(Cache::get("storage:oauth:device:{$this->destination->id}"))->toBeNull();
});

/*
 * A grant that works but a Drive with no room. Storing the token anyway would
 * leave a destination that looks connected and fails every backup — the exact
 * shape of failure this subsystem exists to refuse.
 */
it('refuses to store a token when the folder cannot be created', function () {
    Cache::put("storage:oauth:device:{$this->destination->id}", 'DEV-1', now()->addMinutes(30));
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt'])]);
    fakeWorkspace(['ok' => false, 'folder_id' => null, 'account_email' => null, 'reason' => 'storage.oauth.user_quota']);

    $this->withHeaders(authHeader())->postJson(pollUrl())
        ->assertOk()
        ->assertJsonPath('oauth.status', 'failed');

    expect($this->destination->fresh()->config)->not->toHaveKey('refresh_token');
});

// Polling with nothing in flight is "press Connect again", not a Google
// failure — and it must not send a request asking about a code we do not have.
it('says the code expired when nothing is in flight', function () {
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'x'])]);

    $this->withHeaders(authHeader())->postJson(pollUrl())
        ->assertOk()
        ->assertJsonPath('oauth.status', 'expired');

    Http::assertNothingSent();
});

it('never returns the refresh token to the browser', function () {
    Cache::put("storage:oauth:device:{$this->destination->id}", 'DEV-1', now()->addMinutes(30));
    Http::fake([CONNECT_TOKEN_URL => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-secret'])]);
    fakeWorkspace(['ok' => true, 'folder_id' => 'F', 'account_email' => 'me@gmail.com', 'reason' => null]);

    $response = $this->withHeaders(authHeader())->postJson(pollUrl())->assertOk();

    expect($response->getContent())->not->toContain('rt-secret')
        ->and($response->getContent())->not->toContain('shh');
});

// Approving this writes a credential that can create files in somebody's
// personal Google account. `view` is not enough.
it('refuses a viewer without manage permission', function () {
    $viewer = User::factory()->create();
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson(startUrl())
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
