<?php

use App\Models\User;

it('lets an admin impersonate a regular user (session-based, no token)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$target->id}/impersonate");

    $response->assertCreated()
        ->assertJsonPath('user.id', $target->id)
        ->assertJsonPath('impersonated_by.id', $admin->id)
        ->assertJsonStructure(['user', 'impersonated_by' => ['id', 'username']]);

    // no token in the response — it's a session, not a bearer token
    expect($response->json())->not->toHaveKey('token');
    // the session remembers who is impersonating
    $response->assertSessionHas('impersonator_id', $admin->id);
});

it('reports the impersonator on /auth/me during an impersonated session', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($target)
        ->withSession(['impersonator_id' => $admin->id])
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $target->id)
        ->assertJsonPath('impersonated_by.id', $admin->id)
        ->assertJsonPath('impersonated_by.username', $admin->username);
});

it('returns a null impersonator on /auth/me for a normal session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('impersonated_by', null);
});

it('lets the impersonated session stop impersonating, clearing the marker', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($target)
        ->withSession(['impersonator_id' => $admin->id])
        ->postJson('/api/auth/stop-impersonating');

    $response->assertNoContent();
    $response->assertSessionMissing('impersonator_id');
});

it('rejects stop-impersonating on a normal (non-impersonation) session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/auth/stop-impersonating')
        ->assertUnprocessable();
});

it('blocks an admin from impersonating another admin', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson("/api/admin/users/{$otherAdmin->id}/impersonate")
        ->assertUnprocessable();
});

it('blocks an admin from impersonating themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson("/api/admin/users/{$admin->id}/impersonate")
        ->assertUnprocessable();
});

it('denies a regular user from impersonating anyone', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/admin/users/{$target->id}/impersonate")
        ->assertForbidden();
});

it('logs impersonation start and stop, attributed to the admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/impersonate");
    $this->actingAs($target)
        ->withSession(['impersonator_id' => $admin->id])
        ->postJson('/api/auth/stop-impersonating');

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/activity-log?search='.$admin->username);

    $actions = collect($response->json('activity_log'))->pluck('action');
    expect($actions)->toContain('impersonation_started');
    expect($actions)->toContain('impersonation_stopped');
});
