<?php

use App\Exceptions\BlockedHostException;
use App\Models\GitAccount;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\Storage\Drivers\S3Driver;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Support\RemoteHost;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Bug #34: the host checks judged only the text typed, so a name that
 * resolves to loopback or the cloud metadata address — `127.0.0.1.nip.io` is
 * a public wildcard DNS name for 127.0.0.1 — passed, and the panel connected
 * to itself. Names are now resolved when saved and again when connecting,
 * and HTTPS connections are pinned to the address that was checked.
 *
 * DNS is faked: these names resolve to whatever the map says.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

    RemoteHost::resolveUsing(fn (string $host): array => match ($host) {
        '127.0.0.1.nip.io', 'loop.example.com' => ['127.0.0.1'],
        'metadata.example.com' => ['169.254.169.254'],
        'v6loop.example.com' => ['::1'],
        'split.example.com' => ['203.0.113.10', '127.0.0.1'],
        'git.lan', 'nas.lan' => ['10.0.0.5'],
        'git.example.com', 's3.example.com' => ['203.0.113.10'],
        default => [],
    });

    Http::preventStrayRequests();
});

function hostGuardHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

describe('when saving', function () {
    it('refuses a Git host whose name resolves to loopback or metadata', function (string $host) {
        $this->withHeaders(hostGuardHeaders())->postJson('/api/integrations/git/accounts', [
            'provider' => 'gitlab', 'label' => 'x', 'token' => 'glpat_x', 'host' => "https://{$host}",
        ])->assertUnprocessable()->assertJsonValidationErrors('host');

        expect(GitAccount::count())->toBe(0);
    })->with(['127.0.0.1.nip.io', 'metadata.example.com', 'v6loop.example.com', 'split.example.com']);

    it('still accepts a Git server on the LAN', function () {
        Http::fake(['git.lan/api/v4/user' => Http::response(['username' => 'dev'], 200)]);

        $this->withHeaders(hostGuardHeaders())->postJson('/api/integrations/git/accounts', [
            'provider' => 'gitlab', 'label' => 'LAN', 'token' => 'glpat_x', 'host' => 'https://git.lan',
        ])->assertCreated();
    });

    it('refuses an S3 endpoint whose name resolves to the metadata address', function () {
        $this->withHeaders(hostGuardHeaders())->postJson('/api/integrations/storage/destinations', [
            'name' => 'x', 'provider' => 's3', 'prefix' => 'a/',
            'config' => ['endpoint' => 'https://metadata.example.com', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.endpoint');
    });

    it('refuses an SFTP host whose name resolves to loopback, and keeps the LAN', function () {
        $this->withHeaders(hostGuardHeaders())->postJson('/api/integrations/storage/destinations', [
            'name' => 'x', 'provider' => 'sftp', 'config' => ['host' => '127.0.0.1.nip.io', 'username' => 'u', 'password' => 'p'],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.host');

        $this->withHeaders(hostGuardHeaders())->postJson('/api/integrations/storage/destinations', [
            'name' => 'y', 'provider' => 'sftp', 'config' => ['host' => 'nas.lan', 'username' => 'u', 'password' => 'p'],
        ])->assertJsonMissingValidationErrors('config.host');
    });
});

describe('when connecting', function () {
    it('pins a name to the address it was checked at', function () {
        expect(RemoteHost::pin('https://git.example.com/api/v4'))->toBe('git.example.com:443:203.0.113.10')
            ->and(RemoteHost::pin('https://git.example.com:8443'))->toBe('git.example.com:8443:203.0.113.10')
            ->and(RemoteHost::pin('https://203.0.113.10'))->toBeNull()
            ->and(RemoteHost::pin('https://unknown.example.com'))->toBeNull();

        expect(fn () => RemoteHost::pin('https://loop.example.com'))->toThrow(BlockedHostException::class);
    });

    it('does not call a Git host that has since started resolving to loopback', function () {
        // Saved while it pointed somewhere public; the name moved afterwards.
        $account = GitAccount::create([
            'provider' => 'gitlab', 'label' => 'moved', 'identifier' => 'dev', 'token' => 'glpat_x',
            'host' => 'https://loop.example.com', 'last_verified_at' => now(),
        ]);
        Http::fake();

        $this->withHeaders(hostGuardHeaders())
            ->getJson("/api/integrations/git/accounts/{$account->id}/repositories")
            ->assertStatus(502);

        Http::assertNothingSent();
    });

    it('pins an S3 endpoint, and refuses one that now resolves to metadata', function () {
        $destination = new StorageDestination(['provider' => 's3', 'config' => [
            'endpoint' => 'https://s3.example.com', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's',
        ]]);

        expect(app(S3Driver::class)->config($destination)['http']['curl'][CURLOPT_RESOLVE])->toBe(['s3.example.com:443:203.0.113.10']);

        $destination->config = [...$destination->config, 'endpoint' => 'https://metadata.example.com'];

        expect(fn () => app(S3Driver::class)->config($destination))->toThrow(BlockedHostException::class)
            ->and(app(StorageDriverFactory::class)->for($destination)->preflight($destination))->toBe('storage.test.forbidden_host');
    });
});
