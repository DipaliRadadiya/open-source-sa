<?php

use App\Models\ActivityLog;
use App\Models\CentralConnection;
use App\Models\User;
use App\Services\Central\CentralUser;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/*
 * The connection is one key with full access to the server, so what matters
 * here is not the happy path but the edges: that the key really works, that
 * revoking really stops it, that the machine account it belongs to cannot be
 * reached as if it were a person, and that a stray second click cannot break
 * a live integration.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    // Bearer rather than actingAs: actingAs authenticates the session guard
    // for the whole test, so every assertion about what a token can and
    // cannot reach would pass regardless of the token.
    $this->centralAdmin = fn () => test()->withHeader(
        'Authorization', 'Bearer '.test()->admin->createToken('t', ['*'])->plainTextToken,
    );
});

function centralAdmin(): TestCase
{
    return (test()->centralAdmin)();
}

/**
 * Drop the guards Laravel resolved on an earlier request in the same test.
 *
 * The application is not rebuilt between requests here, so the user the admin
 * calls authenticated as is still cached on the guard — and Sanctum consults
 * that before it ever looks at the Bearer header. Without this, every
 * assertion below about what the central key can reach would answer for the
 * admin instead, and both the "it works" and the "it stopped working" tests
 * would pass no matter what the code did.
 */
function forgetResolvedGuards(): void
{
    app('auth')->forgetGuards();
}

it('reports not connected before anything has happened', function () {
    centralAdmin()->getJson('/api/admin/central')
        ->assertOk()
        ->assertExactJson(['central' => ['connected' => false]]);
});

it('issues a key and records who allowed it', function () {
    $response = centralAdmin()->postJson('/api/admin/central')->assertCreated();

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('central.connected'))->toBeTrue()
        ->and($response->json('central.connected_by.username'))->toBe($this->admin->username);

    // The consent record — not just that a token exists, but who allowed it.
    $connection = CentralConnection::active();
    expect($connection->connected_by_user_id)->toBe($this->admin->id)
        ->and($connection->connected_at)->not->toBeNull();
});

it('stores only the hash of the key, never the key itself', function () {
    $token = centralAdmin()->postJson('/api/admin/central')->json('token');

    // A database leak must not hand anyone a working key.
    expect(PersonalAccessToken::query()->where('token', $token)->exists())->toBeFalse()
        ->and(PersonalAccessToken::findToken($token))->not->toBeNull();
});

it('never returns the key a second time', function () {
    centralAdmin()->postJson('/api/admin/central');

    $response = centralAdmin()->getJson('/api/admin/central')->assertOk();

    expect($response->json('token'))->toBeNull();
});

it('gives the key real access to the panel, as the integration', function () {
    $token = centralAdmin()->postJson('/api/admin/central')->json('token');
    forgetResolvedGuards();

    // The point of the feature: the far end can actually read the panel with
    // nothing but this string — and it arrives as the machine account, not as
    // the administrator who pressed the button.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk();

    expect($response->json('user.username'))->toBe(CentralUser::USERNAME)
        ->and($response->json('user.username'))->not->toBe($this->admin->username);
});

it('refuses to issue a second key while one is live', function () {
    centralAdmin()->postJson('/api/admin/central')->assertCreated();

    // Silently re-issuing would kill a working integration on a stray click,
    // and nobody would connect the two events.
    centralAdmin()->postJson('/api/admin/central')->assertStatus(422);

    expect(CentralConnection::query()->count())->toBe(1);
});

it('stops the key working the moment it is disconnected', function () {
    $token = centralAdmin()->postJson('/api/admin/central')->json('token');

    centralAdmin()->deleteJson('/api/admin/central')->assertNoContent();
    forgetResolvedGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertUnauthorized();

    // The token row is gone; the consent record stays as the history of it.
    expect(PersonalAccessToken::findToken($token))->toBeNull()
        ->and(CentralConnection::query()->count())->toBe(1)
        ->and(CentralConnection::active())->toBeNull();
});

it('still reports when it was last connected after disconnecting', function () {
    centralAdmin()->postJson('/api/admin/central');
    centralAdmin()->deleteJson('/api/admin/central');

    $response = centralAdmin()->getJson('/api/admin/central')->assertOk();

    expect($response->json('central.connected'))->toBeFalse()
        ->and($response->json('central.connected_at'))->not->toBeNull()
        ->and($response->json('central.revoked_at'))->not->toBeNull();
});

it('rejects disconnecting when nothing is connected', function () {
    centralAdmin()->deleteJson('/api/admin/central')->assertStatus(422);
});

it('can be connected again after being disconnected', function () {
    centralAdmin()->postJson('/api/admin/central');
    centralAdmin()->deleteJson('/api/admin/central');

    $token = centralAdmin()->postJson('/api/admin/central')->assertCreated()->json('token');
    forgetResolvedGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk();

    // Same identity throughout, so the activity log stays attributable.
    expect(User::query()->where('is_system', true)->count())->toBe(1);
});

it('logs both the granting and the withdrawal of consent', function () {
    centralAdmin()->postJson('/api/admin/central');
    centralAdmin()->deleteJson('/api/admin/central');

    expect(ActivityLog::query()->where('type', 'central')->pluck('action')->all())
        ->toBe(['connected', 'disconnected']);
});

it('denies a non-admin', function () {
    $token = User::factory()->create()->createToken('t', ['*'])->plainTextToken;
    forgetResolvedGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/central')->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/central')->assertForbidden();
});

it('denies an unauthenticated caller', function () {
    $this->getJson('/api/admin/central')->assertUnauthorized();
    $this->postJson('/api/admin/central')->assertUnauthorized();
    $this->deleteJson('/api/admin/central')->assertUnauthorized();
});

describe('the machine account', function () {
    it('cannot log in', function () {
        $account = app(CentralUser::class)->ensure();

        // It has a random password nobody holds, but the rule is explicit
        // rather than incidental.
        $this->postJson('/api/auth/login', [
            'username' => $account->username,
            'password' => 'Password123',
        ])->assertStatus(422);
    });

    it('is not listed or countable as a panel user', function () {
        app(CentralUser::class)->ensure();

        $usernames = collect(centralAdmin()->getJson('/api/admin/users')->json('users'))->pluck('username');
        expect($usernames)->not->toContain(CentralUser::USERNAME);

        // The list and the dashboard count disagreeing reads as a bug.
        expect(centralAdmin()->getJson('/api/admin/dashboard')->json('dashboard.users.total'))
            ->toBe(User::query()->where('is_system', false)->count());
    });

    it('cannot be edited, deleted or impersonated', function () {
        $account = app(CentralUser::class)->ensure();

        centralAdmin()->putJson("/api/admin/users/{$account->id}", [
            'name' => 'Taken Over', 'username' => 'takenover', 'is_admin' => true,
        ])->assertNotFound();

        centralAdmin()->deleteJson("/api/admin/users/{$account->id}")->assertNotFound();
        centralAdmin()->postJson("/api/admin/users/{$account->id}/impersonate")->assertNotFound();
        centralAdmin()->putJson("/api/admin/users/{$account->id}/reset-password", [
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertNotFound();
    });

    it('does not take over a username a person already holds', function () {
        User::factory()->create(['username' => CentralUser::USERNAME]);

        $account = app(CentralUser::class)->ensure();

        // Reusing that row would have minted a full-access key on a real
        // person's account.
        expect($account->username)->not->toBe(CentralUser::USERNAME)
            ->and($account->is_system)->toBeTrue();
    });

    it('does not count as a registered user for the first-admin check', function () {
        app(CentralUser::class)->ensure();
        User::query()->where('is_system', false)->delete();

        // Otherwise a machine account would close registration on a panel
        // that has no administrator, with no way back in.
        $this->postJson('/api/auth/register', [
            'name' => 'First Admin',
            'username' => 'firstadmin',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated();
    });
});
