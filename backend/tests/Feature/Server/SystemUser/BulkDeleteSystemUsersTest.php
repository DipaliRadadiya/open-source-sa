<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Deleting several system users at once (v7's bulk delete, 2026-10-17).
 *
 * Each one goes through the single delete, so what matters here is that a
 * refusal for one account is reported beside it and does not stop the others —
 * and that none of the single delete's guards is skipped on the way.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function bulkDelete(array $body, ?User $user = null)
{
    return test()->withHeader('Authorization', 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken)
        ->deleteJson('/api/system-users', $body);
}

function makeUser(string $name): SystemUser
{
    return SystemUser::create(['username' => $name, 'home_path' => "/home/{$name}", 'shell' => '/bin/bash']);
}

it('deletes each one with userdel and reports them all', function () {
    Process::fake();
    $a = makeUser('alpha');
    $b = makeUser('bravo');

    bulkDelete(['ids' => [$b->id, $a->id]])
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('succeeded', [$a->id, $b->id])
        ->assertJsonPath('failed', []);

    expect(SystemUser::count())->toBe(0);
    Process::assertRan(fn ($p) => $p->command === ['userdel', '-r', 'alpha']);
    Process::assertRan(fn ($p) => $p->command === ['userdel', '-r', 'bravo']);
});

it('refuses one that still owns a site, names why, and still deletes the others', function () {
    Process::fake();
    $owner = makeUser('owner');
    $free = makeUser('free');
    Application::forceCreate([
        'system_user_id' => $owner->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active',
    ]);

    bulkDelete(['ids' => [$owner->id, $free->id]])
        ->assertOk()
        ->assertJsonPath('deleted', false)
        ->assertJsonPath('succeeded', [$free->id])
        ->assertJsonPath('failed.0', ['id' => $owner->id, 'username' => 'owner', 'reason' => 'has_applications']);

    expect(SystemUser::find($owner->id))->not->toBeNull()
        ->and(SystemUser::find($free->id))->toBeNull();
    Process::assertNotRan(fn ($p) => $p->command === ['userdel', '-r', 'owner']);
});

it('reports a signed-in account as has_processes, and ends sessions when asked', function () {
    $signedIn = new ArrayObject(['busy' => true]);
    Process::fake(function ($p) use ($signedIn) {
        $command = $p->command;

        return match (true) {
            $command === ['userdel', '-r', 'busy'] && $signedIn['busy'] => Process::result(errorOutput: 'userdel: user busy is currently used by process 4101', exitCode: 8),
            ($command[0] ?? '') === 'ps' => $signedIn['busy'] ? Process::result(output: "4101\n") : Process::result(exitCode: 1),
            ($command[0] ?? '') === 'kill' => tap(Process::result(), fn () => $signedIn['busy'] = false),
            default => Process::result(),
        };
    });
    $busy = makeUser('busy');

    bulkDelete(['ids' => [$busy->id]])
        ->assertOk()
        ->assertJsonPath('failed.0.reason', 'has_processes');
    expect(SystemUser::find($busy->id))->not->toBeNull();

    bulkDelete(['ids' => [$busy->id], 'end_sessions' => true])
        ->assertOk()
        ->assertJsonPath('succeeded', [$busy->id]);
    expect(SystemUser::find($busy->id))->toBeNull();
});

it('gives a userdel failure its own reference, and goes on to the next', function () {
    Process::fake(fn ($p) => $p->command === ['userdel', '-r', 'broken']
        ? Process::result(errorOutput: 'userdel: cannot remove', exitCode: 12)
        : Process::result());
    $broken = makeUser('broken');
    $ok = makeUser('okay');

    $failed = bulkDelete(['ids' => [$broken->id, $ok->id]])
        ->assertOk()
        ->assertJsonPath('succeeded', [$ok->id])
        ->json('failed.0');

    expect($failed['reason'])->toBe('failed')
        ->and($failed['reference'] ?? '')->not->toBe('');
});

it('validates the selection', function (array $body, string $field) {
    Process::fake();
    makeUser('alpha');

    bulkDelete($body)->assertStatus(422)->assertJsonValidationErrors($field);

    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'userdel');
})->with([
    'nothing' => [['ids' => []], 'ids'],
    'unknown id' => [['ids' => [99999]], 'ids.0'],
    'duplicate' => [['ids' => [1, 1]], 'ids.0'],
    'too many' => [['ids' => range(1, 51)], 'ids'],
]);

it('is refused to a user who may only view system users', function () {
    Process::fake();
    $a = makeUser('alpha');
    $viewer = User::factory()->create();
    grantPermission($viewer, 'system_user', view: true, manage: false);

    bulkDelete(['ids' => [$a->id]], $viewer)->assertForbidden();

    expect(SystemUser::find($a->id))->not->toBeNull();
});

it('leaves the single delete where it was', function () {
    Process::fake();
    $a = makeUser('alpha');

    test()->withHeader('Authorization', 'Bearer '.$this->admin->createToken('t')->plainTextToken)
        ->deleteJson("/api/system-users/{$a->id}")
        ->assertNoContent();
});
