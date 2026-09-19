<?php

namespace Tests\Feature\Server\Storage;

use App\Services\Server\Backups\Storage\GoogleDeviceFlow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Every fake here is keyed to one endpoint rather than a blanket
 * `Http::fake()`. The device flow talks to two URLs with different shapes, and
 * a catch-all would let a test pass while the code called the wrong one.
 */

const DEVICE_URL = 'oauth2.googleapis.com/device/code';
const TOKEN_URL = 'oauth2.googleapis.com/token';

function flow(): GoogleDeviceFlow
{
    return app(GoogleDeviceFlow::class);
}

it('returns a user code and the URL to type it into', function () {
    Http::fake([DEVICE_URL => Http::response([
        'device_code' => 'DEV-1',
        'user_code' => 'HXTR-9F2K',
        'verification_url' => 'https://www.google.com/device',
        'interval' => 5,
        'expires_in' => 1800,
    ])]);

    $result = flow()->start('client-abc');

    expect($result['ok'])->toBeTrue()
        ->and($result['user_code'])->toBe('HXTR-9F2K')
        ->and($result['device_code'])->toBe('DEV-1')
        ->and($result['verification_url'])->toBe('https://www.google.com/device')
        ->and($result['interval'])->toBe(5);
});

// The scope is the whole reason this flow is usable: Google allows only a
// short list here, and asking for full `drive` would be rejected outright.
it('requests drive.file and nothing wider', function () {
    Http::fake([DEVICE_URL => Http::response(['device_code' => 'd', 'user_code' => 'u'])]);

    flow()->start('client-abc');

    Http::assertSent(fn (Request $r) => $r['scope'] === 'https://www.googleapis.com/auth/drive.file');
});

/*
 * Google renamed this field; the documented name is `verification_url` and
 * newer responses carry `verification_uri`. Reading only one would leave the
 * operator holding a code with nowhere to type it.
 */
it('accepts the renamed verification field', function () {
    Http::fake([DEVICE_URL => Http::response([
        'device_code' => 'd', 'user_code' => 'u', 'verification_uri' => 'https://google.com/dev',
    ])]);

    expect(flow()->start('c')['verification_url'])->toBe('https://google.com/dev');
});

it('names a bad client id rather than reporting a generic failure', function () {
    Http::fake([DEVICE_URL => Http::response(['error' => 'invalid_client'], 401)]);

    $result = flow()->start('wrong');

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('storage.oauth.bad_client');
});

/*
 * The exact body Google returned on a real box, captured rather than invented.
 *
 * This is the failure a first-time operator actually hits, because "TVs and
 * Limited Input devices" is an absurd-looking choice for a server control
 * panel and everyone reaches for "Web application" instead. It arrives as
 * `invalid_client` — the same code as an unknown client id — so the previous
 * message sent them to re-check a client id that was perfectly correct.
 *
 * The suite could not catch it: the fake carried the code with no description,
 * which is a response Google never actually sends.
 */
it('names a wrong client type instead of blaming the client id', function () {
    Http::fake([DEVICE_URL => Http::response([
        'error' => 'invalid_client',
        'error_description' => "Only clients of type \u0026#39;TVs and Limited Input devices\u0026#39; can use the OAuth 2.0 flow for TV and Limited-Input Device Applications. Please create and use an appropriate client.",
        'error_uri' => 'https://developers.google.com/identity/protocols/oauth2/limited-input-device#creatingcred',
    ], 401)]);

    expect(flow()->start('web-app-client.apps.googleusercontent.com'))
        ->ok->toBeFalse()
        ->reason->toBe('storage.oauth.wrong_client_type');
});

// An unknown client id still reports as one — the new branch must not swallow
// the case it was carved out of.
it('still names an unrecognised client id', function () {
    Http::fake([DEVICE_URL => Http::response([
        'error' => 'invalid_client',
        'error_description' => 'The OAuth client was not found.',
    ], 401)]);

    expect(flow()->start('nope')['reason'])->toBe('storage.oauth.bad_client');
});

it('treats a success with no codes in it as a failure', function () {
    Http::fake([DEVICE_URL => Http::response(['expires_in' => 1800])]);

    expect(flow()->start('c')['ok'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Polling
// ---------------------------------------------------------------------------

it('reports pending while nobody has approved', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'authorization_pending'], 428)]);

    expect(flow()->poll('c', 's', 'd')['status'])->toBe('pending');
});

it('distinguishes slow_down from a failure', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'slow_down'], 403)]);

    // Backing off is not an error — treating it as one would abandon a code
    // the operator is in the middle of approving.
    expect(flow()->poll('c', 's', 'd')['status'])->toBe('slow_down');
});

/*
 * One outcome per test on purpose: a second `Http::fake()` inside the same
 * test does not replace the first stub, so both halves answered with the first
 * response and the expiry assertion was never really run. It failed loudly
 * here, but the same shape passes silently when the two expectations agree.
 */
it('reports a denied approval as its own outcome', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'access_denied'], 403)]);

    expect(flow()->poll('c', 's', 'd'))
        ->status->toBe('denied')
        ->reason->toBe('storage.oauth.denied');
});

it('reports an expired code as its own outcome', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'expired_token'], 400)]);

    expect(flow()->poll('c', 's', 'd'))
        ->status->toBe('expired')
        ->reason->toBe('storage.oauth.code_expired');
});

it('returns the refresh token once approved', function () {
    Http::fake([TOKEN_URL => Http::response([
        'access_token' => 'at', 'refresh_token' => 'rt-keepme', 'expires_in' => 3599,
    ])]);

    expect(flow()->poll('c', 's', 'd'))
        ->status->toBe('approved')
        ->refresh_token->toBe('rt-keepme');
});

/*
 * The trap worth a test of its own: Google omits `refresh_token` when the
 * account has already granted this client before. Storing that would leave a
 * destination that works for an hour and then fails every backup forever, with
 * nothing to renew from.
 */
it('refuses an approval that carries no refresh token', function () {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'at', 'expires_in' => 3599])]);

    expect(flow()->poll('c', 's', 'd'))
        ->status->toBe('failed')
        ->reason->toBe('storage.oauth.no_refresh_token');
});

// A blinking network is not a denied authorization. The code is still valid
// and the operator may already have approved it, so the caller keeps polling.
it('keeps polling when the network fails rather than abandoning the code', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(flow()->poll('c', 's', 'd')['status'])->toBe('pending');
});

// ---------------------------------------------------------------------------
// Refreshing
// ---------------------------------------------------------------------------

it('exchanges a refresh token for an access token', function () {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'fresh', 'expires_in' => 3599])]);

    expect(flow()->accessToken('c', 's', 'rt'))
        ->ok->toBeTrue()
        ->access_token->toBe('fresh');
});

/*
 * `invalid_grant` is the single most likely production failure: the operator
 * revoked access, or left the OAuth app in "Testing", where Google expires
 * refresh tokens after about a week. Both need a human, so it must not read as
 * "bad credentials" and send them to re-check a client id that is fine.
 */
it('names a revoked or expired grant as its own cause', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(flow()->accessToken('c', 's', 'dead'))
        ->ok->toBeFalse()
        ->reason->toBe('storage.oauth.revoked');
});

it('sends the refresh grant type, not the device one', function () {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'a'])]);

    flow()->accessToken('c', 's', 'rt');

    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token');
});
