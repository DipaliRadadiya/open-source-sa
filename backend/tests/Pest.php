<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
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
 * A database client's answer to a query a fake would otherwise leave silent.
 *
 * `null` for anything that is not a database client, so a fake falls through
 * to its own handling and this only ever adds an answer where there was none.
 *
 * Provisioning asks the engine whether a generated name is free before using
 * it. A bare `Process::result(exitCode: 0)` answers that probe with an empty
 * string — which reads as "taken", so the allocator walks twenty candidates
 * and fails the install. No real client answers a SELECT with nothing, so the
 * fake, not the code, is what was wrong.
 */
function fakeDatabaseAnswer(mixed $process): ?FakeProcessResult
{
    return in_array($process->command[0] ?? '', ['mysql', 'mariadb'], true)
        ? Process::result(output: '1')
        : null;
}

/**
 * Answer as a server whose SQL engine is reachable.
 *
 * The catalog asks `available()` — a live query — before offering any type
 * that needs a database, and the create endpoint refuses one that is blocked.
 * A fixture that fakes nothing is therefore a claim about a server with no
 * database engine at all, on which creating WordPress is correctly refused.
 * Most tests do not mean to make that claim; they mean "an ordinary server".
 *
 * Everything else *fails*, which is the important half. Falling through to an
 * empty success turns "this command could not be run" into "it ran and printed
 * nothing" — two different servers, and the second is one that cannot exist. A
 * config reader then sees a file that is present and empty rather than absent,
 * and fails somewhere far away from the fake that caused it.
 */
function fakeUsableSqlEngine(): void
{
    Process::fake(fn (mixed $process) => fakeDatabaseAnswer($process) ?? Process::result(exitCode: 1));
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
