<?php

use App\Actions\Auth\RegisterFirstAdmin;
use App\Models\User;
use App\Services\AdministratorRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;

it('registers the first user as admin with the Administrator role', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'First User',
        'username' => 'firstadmin',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.username', 'firstadmin')
        ->assertJsonPath('user.is_admin', true)
        ->assertJsonStructure(['user' => ['id', 'name', 'username', 'is_admin', 'roles'], 'token']);

    $user = User::first();
    expect($user->is_admin)->toBeTrue();
    // gets the protected Administrator role (>= 1 role invariant)
    expect($user->roles()->where('slug', AdministratorRole::SLUG)->exists())->toBeTrue();
});

it('rejects registration once a user already exists', function () {
    User::factory()->admin()->create();

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Second User',
        'username' => 'seconduser',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertForbidden();
    expect(User::count())->toBe(1);
});

it('validates registration input', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => '',
        'username' => '',
        'password' => 'short',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'username', 'password']);
});

it('refuses a second first-admin that got past the request check at the same time', function () {
    // RegisterRequest::authorize() runs before the account is created, so two
    // sign-ups arriving together on a fresh panel both passed it. The action
    // asks again under a lock (found in code review 2026-09-29).
    $this->seed(PermissionSeeder::class);
    $register = app(RegisterFirstAdmin::class);

    $register->execute(['name' => 'Owner', 'username' => 'owner', 'password' => 'Str0ng-Passw0rd!']);

    expect(fn () => $register->execute(['name' => 'Late', 'username' => 'late', 'password' => 'Str0ng-Passw0rd!']))
        ->toThrow(AuthorizationException::class)
        ->and(User::where('is_system', false)->count())->toBe(1);
});
