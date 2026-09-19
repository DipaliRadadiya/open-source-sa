<?php

namespace Tests\Feature\Server\Storage;

use App\Services\Server\Backups\Storage\GoogleOauthTokens;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The half of Google OAuth that does not care how consent was obtained. These
 * tests moved here with the method, from the device-flow suite — spending a
 * refresh token is identical whether it arrived by approving a code on a phone
 * or by coming back through a browser redirect, which is the whole reason the
 * two were separated.
 */

const TOKENS_URL = 'oauth2.googleapis.com/token';

function tokens(): GoogleOauthTokens
{
    return new GoogleOauthTokens;
}

it('exchanges a refresh token for an access token', function () {
    Http::fake([TOKENS_URL => Http::response(['access_token' => 'fresh', 'expires_in' => 3599])]);

    expect(tokens()->accessToken('c', 's', 'rt'))
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
    Http::fake([TOKENS_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(tokens()->accessToken('c', 's', 'dead'))
        ->ok->toBeFalse()
        ->reason->toBe('storage.oauth.revoked');
});

it('sends the refresh grant type', function () {
    Http::fake([TOKENS_URL => Http::response(['access_token' => 'a'])]);

    tokens()->accessToken('c', 's', 'rt');

    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token');
});

// An empty body with a 200 is not a token. Treating it as one would store a
// destination that passes preflight and fails every upload.
it('refuses a success that carries no access token', function () {
    Http::fake([TOKENS_URL => Http::response([])]);

    expect(tokens()->accessToken('c', 's', 'rt'))
        ->ok->toBeFalse()
        ->reason->toBe('storage.test.invalid_credentials');
});
