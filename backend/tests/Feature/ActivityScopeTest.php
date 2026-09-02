<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityScopes;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('t')->plainTextToken;

    $this->log = function (string $type, string $action, ?User $user = null) {
        return ActivityLog::create([
            'user_id' => ($user ?? $this->user)->id,
            'type' => $type,
            'action' => $action,
            'properties' => [],
        ]);
    };
});

function asUser(string $method, string $uri): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->json($method, $uri);
}

it('classifies every activity type into exactly one scope', function () {
    $scopes = app(ActivityScopes::class);

    $types = collect(Lang::get('activity'))
        ->keys()
        ->map(fn (string $key) => Str::before($key, '.'))
        ->unique()
        ->sort()
        ->values();

    // A type in no scope is filtered out of both screens and nothing fails —
    // it is simply invisible. This test is the whole reason the map is config
    // rather than something derived.
    $unclassified = $types->reject(fn (string $type) => $scopes->for($type) !== null);

    expect($unclassified->all())->toBe([]);

    // And a type in two scopes would be double-counted.
    $duplicated = $types->filter(function (string $type) use ($scopes) {
        $hits = collect($scopes->all())->filter(fn (string $s) => in_array($type, $scopes->types($s), true));

        return $hits->count() > 1;
    });

    expect($duplicated->all())->toBe([]);
});

it('does not map a scope to a type that no longer exists', function () {
    $scopes = app(ActivityScopes::class);

    $known = collect(Lang::get('activity'))
        ->keys()
        ->map(fn (string $key) => Str::before($key, '.'))
        ->unique();

    $stale = collect($scopes->all())
        ->flatMap(fn (string $scope) => $scopes->types($scope))
        ->reject(fn (string $type) => $known->contains($type));

    expect($stale->values()->all())->toBe([]);
});

it('tells each row which half of the panel it is about', function () {
    ($this->log)('user', 'logged_in');
    ($this->log)('firewall', 'rule_added');

    $rows = collect(asUser('GET', '/api/activity-log')->json('activity_log'))->keyBy('type');

    expect($rows['user']['scope'])->toBe('account')
        ->and($rows['firewall']['scope'])->toBe('server');
});

it('filters to account activity', function () {
    ($this->log)('user', 'logged_in');
    ($this->log)('user', 'password_changed');
    ($this->log)('firewall', 'rule_added');
    ($this->log)('database', 'created');

    $rows = collect(asUser('GET', '/api/activity-log?filter[scope]=account')->json('activity_log'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('scope')->unique()->all())->toBe(['account']);
});

it('filters to server activity', function () {
    ($this->log)('user', 'logged_in');
    ($this->log)('firewall', 'rule_added');
    ($this->log)('database', 'created');

    $rows = collect(asUser('GET', '/api/activity-log?filter[scope]=server')->json('activity_log'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('type')->sort()->values()->all())->toBe(['database', 'firewall']);
});

it('composes scope with the filters that already existed', function () {
    ($this->log)('firewall', 'rule_added');
    ($this->log)('database', 'created');
    ($this->log)('user', 'logged_in');

    expect(collect(asUser('GET', '/api/activity-log?filter[scope]=server&filter[type]=firewall')->json('activity_log')))
        ->toHaveCount(1);

    // A contradiction returns nothing rather than quietly dropping one side.
    expect(collect(asUser('GET', '/api/activity-log?filter[scope]=account&filter[type]=firewall')->json('activity_log')))
        ->toHaveCount(0);

    expect(collect(asUser('GET', '/api/activity-log?filter[scope]=server&search=data')->json('activity_log')))
        ->toHaveCount(1);
});

it('searches server activity without case bias', function () {
    $this->seed(PermissionSeeder::class);
    grantPermission($this->user, 'activity_log');
    ($this->log)('firewall', 'rule_added');

    asUser('GET', '/api/server/activity-log?search=FIREWALL')
        ->assertOk()
        ->assertJsonCount(1, 'activity_log')
        ->assertJsonPath('activity_log.0.type', 'firewall');
});

it('rejects a scope it does not know instead of returning an empty page', function () {
    ($this->log)('user', 'logged_in');

    // Silently ignoring it would show everything and look like a bug in the
    // filter; silently matching nothing would look like an empty history.
    asUser('GET', '/api/activity-log?filter[scope]=nonsense')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filter.scope');
});

it('cannot be used to reach another user rows', function () {
    $other = User::factory()->create();
    ($this->log)('firewall', 'rule_added', $other);
    ($this->log)('database', 'created');

    // The self-scope is applied first and unconditionally.
    $rows = collect(asUser('GET', '/api/activity-log?filter[scope]=server')->json('activity_log'));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['type'])->toBe('database');
});

it('offers only the scopes the caller has history in', function () {
    ($this->log)('user', 'logged_in');

    // An option guaranteed to match nothing is worse than a short list — the
    // same rule the `types` list here already follows.
    expect(asUser('GET', '/api/activity-log/filters')->json('scopes'))
        ->toBe([['value' => 'account', 'label' => 'Account']]);

    ($this->log)('firewall', 'rule_added');

    expect(collect(asUser('GET', '/api/activity-log/filters')->json('scopes'))->pluck('value')->all())
        ->toBe(['account', 'server']);
});

it('labels the scopes in the viewer own language', function () {
    ($this->log)('user', 'logged_in');
    ($this->log)('firewall', 'rule_added');

    $labels = collect(test()->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept-Language', 'de')
        ->getJson('/api/activity-log/filters')->json('scopes'))->pluck('label')->all();

    expect($labels)->toBe(['Konto', 'Server']);
});

it('always offers both scopes on the admin log', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    // The admin log is the whole catalog: an option with no rows behind it
    // today is still the right option to offer.
    expect($this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log/filters')->json('scopes'))
        ->toBe([
            ['value' => 'account', 'label' => 'Account'],
            ['value' => 'server', 'label' => 'Server'],
        ]);
});

it('filters the admin log by scope, across users', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $token = $admin->createToken('t')->plainTextToken;

    ($this->log)('user', 'logged_in', $other);
    ($this->log)('firewall', 'rule_added', $other);
    ($this->log)('database', 'created');

    $rows = collect($this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log?filter[scope]=server')->json('activity_log'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('scope')->unique()->all())->toBe(['server']);
});
