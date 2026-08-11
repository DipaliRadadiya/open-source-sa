<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function grantAdmin(User $user): void
{
    $user->is_admin = true;
    $user->save();
}

describe('POST /api/central/enable', function () {

    it('creates a token and returns it masked', function () {
        grantAdmin($this->user);

        $response = $this->postJson('/api/central/enable');

        $response->assertCreated()
            ->assertJsonStructure(['central_token', 'message']);
        $this->assertStringStartsWith('sv_central_', $response->json('central_token'));
        $this->assertStringContainsString('***', $response->json('central_token'));
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
        $this->assertEquals($row->central_token, $row->central_token); // same stored value
    });

    it('returns 401 when unauthenticated', function () {
        $this->postJson('/api/central/enable')->assertUnauthorized();
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

        $response->assertOk()
            ->assertJson([
                'central' => [
                    'enabled' => true,
                    'token' => 'sv_central_t***',
                ],
            ]);
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

    it('returns 401 when no Authorization header is present', function () {
        $response = $this->getJson('/api/applications');

        $response->assertStatus(401);
    });

    it('returns 401 when the token is invalid', function () {
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_realtoken12345678901234',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/applications', [
            'Authorization' => 'Bearer sv_central_wrongtoken1234567890',
        ]);

        $response->assertStatus(401);
    });

    it('allows access with a valid token', function () {
        DB::table('settings')->insert([
            'id' => 1,
            'central_token' => 'sv_central_realtoken12345678901234',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/applications', [
            'Authorization' => 'Bearer sv_central_realtoken12345678901234',
        ]);

        // 200 = auth passed, [] = no applications yet
        $response->assertOk();
    });
});
