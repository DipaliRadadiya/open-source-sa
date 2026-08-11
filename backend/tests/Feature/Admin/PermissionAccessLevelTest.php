<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * The role form's vocabulary.
 *
 * A grant is two booleans in the pivot but only three of the four
 * combinations can be saved, and until now the API never said so — the
 * frontend had to know the rule and invent the three labels, which meant an
 * English permission screen in all eight locales.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

function catalog(): array
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/admin/permissions')
        ->assertOk()
        ->json();
}

it('names the three access levels a grant can hold', function () {
    $levels = catalog()['access_levels'];

    expect(collect($levels)->pluck('key')->all())->toBe(['none', 'view', 'manage']);

    // Every one carries a sentence, because "manage" on its own does not tell
    // an admin that it also grants reading.
    foreach ($levels as $level) {
        expect($level['title'])->not->toBeEmpty()
            ->and($level['description'])->not->toBeEmpty();
    }
});

it('translates the access levels rather than shipping English to every locale', function () {
    $english = catalog()['access_levels'];

    $french = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Accept-Language' => 'fr',
    ])->getJson('/api/admin/permissions')->assertOk()->json('access_levels');

    expect($french[0]['title'])->not->toBe($english[0]['title'])
        ->and($french[0]['title'])->not->toBeEmpty();
});

it('groups the catalog by level and sub-level without losing any permission', function () {
    $payload = catalog();

    $grouped = collect($payload['groups'])->flatMap(fn (array $group) => $group['permissions']);

    // The flat list stays exactly as it was — the grouping is additive.
    expect($grouped)->toHaveCount(count($payload['permissions']))
        ->and($grouped->pluck('name')->sort()->values())
        ->toEqual(collect($payload['permissions'])->pluck('name')->sort()->values());

    foreach ($payload['groups'] as $group) {
        expect($group['sub_level_title'])->not->toBeEmpty();
    }
});

it('never merges the same permission name across two levels into one group', function () {
    // `logs` exists at server level and `app_log` at application level; a
    // group keyed on sub_level alone would put unrelated grants under one
    // select-all control.
    $groups = collect(catalog()['groups']);

    $keys = $groups->map(fn (array $group) => $group['level'].'|'.$group['sub_level']);

    expect($keys->duplicates())->toBeEmpty()
        ->and($groups->pluck('level')->unique()->count())->toBeGreaterThan(1);
});

describe('saving a role with the three-way access field', function () {
    it('stores read-only as view without manage', function () {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/roles', [
                'name' => 'Support',
                'permissions' => [
                    ['level' => 'server', 'name' => 'system_user', 'access' => 'view'],
                ],
            ])->assertCreated();

        $granted = collect($response->json('role.permissions'))->firstWhere('name', 'system_user');

        expect($granted['access'])->toBe('view')
            ->and($granted['permissions'])->toBe(['view' => true, 'manage' => false]);
    });

    it('stores read and write as both', function () {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/roles', [
                'name' => 'Ops',
                'permissions' => [
                    ['level' => 'server', 'name' => 'system_user', 'access' => 'manage'],
                ],
            ])->assertCreated();

        $granted = collect($response->json('role.permissions'))->firstWhere('name', 'system_user');

        expect($granted['access'])->toBe('manage')
            ->and($granted['permissions'])->toBe(['view' => true, 'manage' => true]);

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'Ops')->first());

        expect($user->canManage('system_user'))->toBeTrue()
            ->and($user->canView('system_user'))->toBeTrue();
    });

    it('stores no access as neither', function () {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/roles', [
                'name' => 'Nobody',
                'permissions' => [
                    ['level' => 'server', 'name' => 'system_user', 'access' => 'none'],
                ],
            ])->assertCreated();

        $granted = collect($response->json('role.permissions'))->firstWhere('name', 'system_user');

        expect($granted['access'])->toBe('none')
            ->and($granted['permissions'])->toBe(['view' => false, 'manage' => false]);
    });

    it('refuses an access level that is not one of the three', function () {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/roles', [
                'name' => 'Bad',
                'permissions' => [
                    ['level' => 'server', 'name' => 'system_user', 'access' => 'admin'],
                ],
            ])->assertJsonValidationErrors('permissions.0.access');
    });

    it('still accepts the original view/manage pair', function () {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/roles', [
                'name' => 'Legacy',
                'permissions' => [
                    ['level' => 'server', 'name' => 'system_user', 'view' => false, 'manage' => true],
                ],
            ])->assertCreated();

        $granted = collect($response->json('role.permissions'))->firstWhere('name', 'system_user');

        // manage still implies view, and the collapsed field reports the level
        // that was actually stored rather than the one that was sent.
        expect($granted['access'])->toBe('manage')
            ->and($granted['permissions']['view'])->toBeTrue();
    });
});

it('returns permission titles in the caller locale on the role screen', function () {
    // The catalog localized these and the roles endpoint did not, so the two
    // halves of the same form disagreed in every locale but English.
    $permission = Permission::where('name', 'system_user')->firstOrFail();

    $role = Role::create(['name' => 'Support', 'slug' => 'support']);
    $role->permissions()->attach($permission->id, ['view' => true, 'manage' => false]);

    $french = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Accept-Language' => 'fr',
    ])->getJson('/api/admin/roles')->assertOk();

    $title = collect($french->json('roles'))
        ->firstWhere('name', 'Support')['permissions'][0]['title'];

    expect($title)->toBe(__('nav.system_user', locale: 'fr'))
        ->and($title)->not->toBe($permission->title);
});
