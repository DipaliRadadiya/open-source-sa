<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('lets an admin impersonate a regular user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/admin/users/{$target->id}/impersonate");

    $response->assertCreated()
        ->assertJsonPath('user.id', $target->id)
        ->assertJsonPath('impersonated_by.id', $admin->id)
        ->assertJsonStructure(['user', 'token', 'impersonated_by' => ['id', 'username']]);

    // the issued token belongs to the target and remembers the admin
    $plain = $response->json('token');
    $model = PersonalAccessToken::findToken($plain);
    expect($model->tokenable_id)->toBe($target->id);
    expect($model->impersonated_by)->toBe($admin->id);
});

it('reports the impersonator on /auth/me during an impersonated session', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $adminToken = $admin->createToken('test')->plainTextToken;

    $impersonationToken = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/admin/users/{$target->id}/impersonate")
        ->json('token');

    // Clear the resolved guard so the next request re-authenticates from the
    // new token (in production each request is isolated; the test harness
    // shares the container and would otherwise reuse the admin's guard).
    $this->app['auth']->forgetGuards();

    $response = $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->getJson('/api/auth/me');

    $response->assertOk()
        ->assertJsonPath('user.id', $target->id)
        ->assertJsonPath('impersonated_by.username', $admin->username);
});

it('returns a null impersonator on /auth/me for a normal session', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('impersonated_by', null);
});

it('lets the impersonated session stop impersonating, revoking that token', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $adminToken = $admin->createToken('test')->plainTextToken;

    $impersonationToken = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/admin/users/{$target->id}/impersonate")
        ->json('token');

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->postJson('/api/auth/stop-impersonating')
        ->assertNoContent();

    // the impersonation token is now revoked
    expect(PersonalAccessToken::findToken($impersonationToken))->toBeNull();
    // the admin's own token is untouched
    expect(PersonalAccessToken::findToken($adminToken))->not->toBeNull();
});

it('rejects stop-impersonating on a normal (non-impersonation) session', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/stop-impersonating')
        ->assertUnprocessable();
});

it('blocks an admin from impersonating another admin', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/admin/users/{$otherAdmin->id}/impersonate")
        ->assertUnprocessable();
});

it('blocks an admin from impersonating themselves', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/admin/users/{$admin->id}/impersonate")
        ->assertUnprocessable();
});

it('denies a regular user from impersonating anyone', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/admin/users/{$target->id}/impersonate")
        ->assertForbidden();
});

it('logs impersonation start and stop, attributed to the admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $adminToken = $admin->createToken('test')->plainTextToken;

    $impersonationToken = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/admin/users/{$target->id}/impersonate")
        ->json('token');
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->postJson('/api/auth/stop-impersonating');
    $this->app['auth']->forgetGuards();

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/admin/activity-log?search='.$admin->username);

    $actions = collect($response->json('activity_log'))->pluck('action');
    expect($actions)->toContain('impersonation_started');
    expect($actions)->toContain('impersonation_stopped');
});
