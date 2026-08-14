<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * Authentication is established per test, not in `beforeEach`. It used to be
 * global, which meant the four "returns 401 when unauthenticated" tests were
 * making authenticated requests and asserting against a state they had
 * already left.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

function grantAdmin(User $user): void
{
    $user->is_admin = true;
    $user->save();
    test()->actingAs($user);
}

describe('POST /api/central/enable', function () {

    it('creates a token and returns the raw value once', function () {
        grantAdmin($this->user);

        $response = $this->postJson('/api/central/enable');

        $response->assertCreated()
            ->assertJsonStructure(['central_token', 'message']);
        $this->assertStringStartsWith('sv_central_', $response->json('central_token'));
        $this->assertStringNotContainsString('***', $response->json('central_token'));
    });

    it('stores the raw token in the settings table', function () {
        grantAdmin($this->user);

        $response = $this->postJson('/api/central/enable');

        $row = DB::table('settings')->where('id', 1)->first();
        $this->assertNotNull($row->central_token);
        $this->assertStringStartsWith('sv_central_', $row->central_token);
    });

    it('replaces any previous token on re-enable', function () {
        grantAdmin($this->user);

        $r1 = $this->postJson('/api/central/enable');
        $token1 = $r1->json('central_token');

        $r2 = $this->postJson('/api/central/enable');
        $token2 = $r2->json('central_token');

        $this->assertNotEquals($token1, $token2);

        $row = DB::table('settings')->where('id', 1)->first();
        $this->assertEquals($token2, $row->central_token);
        $this->assertNotEquals($token1, $token2);
    });

    it('returns 401 when unauthenticated', function () {
        $this->postJson('/api/central/enable')->assertUnauthorized();
    });

    it('denies a non-administrator from creating a central token', function () {
        $this->actingAs($this->user)
            ->postJson('/api/central/enable')
            ->assertForbidden();
    });
});

describe('GET /api/central/status', function () {

    it('returns enabled=false when no token exists', function () {
        grantAdmin($this->user);

        $response = $this->getJson('/api/central/status');

        $response->assertOk()
            ->assertJson(['central' => ['enabled' => false]]);
    });

    it('returns enabled=true with masked token when connected', function () {
        grantAdmin($this->user);
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_testtoken123456789012',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/central/status');

        $response->assertOk()->assertJsonPath('central.enabled', true);

        // Asserted by shape, not as a literal: the mask keeps the first 12
        // characters and replaces each remaining one, so a hard-coded
        // "sv_central_t***" was describing a different masker than the one
        // that ships.
        $token = $response->json('central.token');

        expect($token)->toStartWith('sv_central_t')
            ->and($token)->not->toContain('testtoken')
            ->and(str_replace('*', '', substr($token, 12)))->toBe('');
    });

    it('never returns the raw token', function () {
        grantAdmin($this->user);
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_testtoken123456789012',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $content = $this->getJson('/api/central/status')->getContent();

        $this->assertStringNotContainsString('testtoken123456789012', $content);
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson('/api/central/status')->assertUnauthorized();
    });
});

describe('DELETE /api/central', function () {

    it('clears the token from settings', function () {
        grantAdmin($this->user);
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_testtoken123456789012',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/api/central')->assertOk();

        $row = DB::table('settings')->where('id', 1)->first();
        $this->assertNull($row->central_token);
    });

    it('returns 401 when unauthenticated', function () {
        $this->deleteJson('/api/central')->assertUnauthorized();
    });
});

describe('CentralSystemGuard middleware', function () {

    /*
     * The guard is an authenticator, not a gate: it signs the central panel in
     * when the server's token is presented and does nothing at all otherwise.
     * So the refusals below come from `auth:sanctum`, exactly as they would on
     * any route — which is the point. It used to answer 401 itself, which is
     * why it could only ever be applied to routes central alone used.
     *
     * The route is registered here rather than borrowing a real one so the
     * test covers the middleware and not whatever permissions a real endpoint
     * happens to require.
     */
    beforeEach(function () {
        Route::middleware(['auth:sanctum'])
            ->get('/api/test-central-guard', fn () => response()->json(['ok' => true]));
    });

    it('refuses a request with no Authorization header', function () {
        $this->getJson('/api/test-central-guard')->assertStatus(401);
    });

    it('refuses a token that is not the stored one', function () {
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_realtoken12345678901234',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/test-central-guard', [
            'Authorization' => 'Bearer sv_central_wrongtoken1234567890',
        ])->assertStatus(401);
    });

    it('signs the central panel in with a valid token', function () {
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_realtoken12345678901234',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/test-central-guard', [
            'Authorization' => 'Bearer sv_central_realtoken12345678901234',
        ])->assertOk()->assertJson(['ok' => true]);
    });
});
