<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Build a valid POST /admin/users payload for the RBAC model
 * (is_admin + role_ids). Ensures a role exists to satisfy the
 * "every user >= 1 role" rule.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function userPayload(array $overrides = []): array
{
    $role = Role::firstOrCreate(['slug' => 'test-staff'], ['name' => 'Test Staff']);

    return array_merge([
        'name' => 'New User',
        'username' => 'newuser',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'is_admin' => false,
        'role_ids' => [$role->id],
    ], $overrides);
}

/**
 * Grant a permission to a user via a one-off role (permissions are role-based
 * only — there are no direct per-user grants). Creates a role holding the
 * given permission with the requested abilities and assigns it to the user.
 */
function grantPermission(User $user, string $permissionName, bool $view = true, bool $manage = false): void
{
    $permission = Permission::firstWhere('name', $permissionName);
    $suffix = $permissionName.'-'.($manage ? 'm' : 'v').'-'.$user->id;

    $role = Role::create([
        'name' => 'Grant '.$suffix,
        'slug' => Str::slug('grant-'.$suffix),
    ]);
    $role->permissions()->attach($permission->id, ['view' => $view || $manage, 'manage' => $manage]);

    $user->roles()->syncWithoutDetaching([$role->id]);
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/
