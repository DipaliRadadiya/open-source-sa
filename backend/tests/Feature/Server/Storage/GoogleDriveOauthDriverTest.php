<?php

namespace Tests\Feature\Server\Storage;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\Drivers\GoogleDriveOauthDriver;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Support\Facades\Http;

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
    ], $config);

    return $destination;
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

    expect(oauthDriver()->preflight(oauthDestination()))->toBeNull();
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
