<?php

use App\Models\Application;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Testing\TestResponse;

/**
 * Password protection may not be put in front of an application whose own
 * client signs in with the `Authorization` header.
 *
 * Verified against a live Node-RED site before this rule existed. HTTP carries
 * one `Authorization` header per request, and both layers need it:
 *
 *   Authorization: Basic  -> nginx passes, Node-RED answers 401 Bearer
 *   Authorization: Bearer -> nginx answers 401 Basic
 *
 * The token cannot travel any other way either -- `?access_token=` and a
 * cookie were both refused -- so /settings, /flows and /nodes were unreachable
 * in every combination and the editor could not load or deploy a flow. The
 * visible symptom was being asked to sign in twice; the real damage was that
 * the application no longer worked.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

function protect(Application $application, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson("/api/applications/{$application->id}/security", array_merge([
            'enabled' => true,
            'username' => 'admin',
            'password' => 'a-long-enough-password',
        ], $body));
}

/** Disabling sends only the toggle, which is what the UI does. */
function unprotect(Application $application): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson("/api/applications/{$application->id}/security", ['enabled' => false]);
}

it('refuses to protect an application that signs in with the Authorization header', function () {
    $application = Application::factory()->create(['site_type' => 'nodered']);

    protect($application)
        ->assertStatus(422)
        ->assertJsonValidationErrors('enabled');

    expect($application->fresh()->basic_auth_enabled)->toBeFalse();
});

it('explains why rather than just refusing', function () {
    $application = Application::factory()->create(['site_type' => 'nodered']);

    $message = protect($application)->json('errors.enabled.0');

    // The reader has to be able to act on this: it must name the header that
    // collides and say the application already has its own credentials.
    expect($message)->toContain('Authorization')
        ->and($message)->toContain('nodered');
});

it('still allows protecting an application that uses cookies or sessions', function () {
    // WordPress signs in with a cookie, which does not collide.
    $application = Application::factory()->create(['site_type' => 'wordpress']);

    // Not asserting 200: enabling protection writes an htpasswd file and
    // reloads nginx, which needs a real server. What matters here is that
    // validation does NOT reject it, so a 422 on `enabled` is the failure.
    $response = protect($application);

    expect($response->status())->not->toBe(422);
});

it('always allows turning protection off', function () {
    // A site type that cannot be protected must still be able to unprotect --
    // otherwise an application protected before this rule existed is stuck.
    $application = Application::factory()->create([
        'site_type' => 'nodered',
        'basic_auth_enabled' => true,
    ]);

    expect(unprotect($application)->status())->not->toBe(422);
});

it('tells the frontend which applications can be protected', function () {
    $manager = app(SiteTypeManager::class);

    expect($manager->find('nodered')?->authorizationHeaderAuth())->toBeTrue()
        ->and($manager->find('wordpress')?->authorizationHeaderAuth())->toBeFalse();
});

it('defaults every other site type to supported', function () {
    // The flag is opt-in: a new site type is protectable unless it declares
    // otherwise, because the collision is the unusual case.
    $manager = app(SiteTypeManager::class);

    $conflicting = array_values(array_filter(
        $manager->all(),
        fn ($type) => $type->authorizationHeaderAuth(),
    ));

    expect(array_map(fn ($type) => $type->name(), $conflicting))->toBe(['nodered']);
});
