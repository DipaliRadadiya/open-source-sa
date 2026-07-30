<?php

use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Models\User;
use App\Services\Timezones;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('t')->plainTextToken;

    $this->get = fn (string $uri) => test()
        ->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson($uri);
});

it('offers only values the settings endpoint will accept', function () {
    $offered = collect(app(Timezones::class)->grouped())
        ->flatMap(fn (array $group) => collect($group['zones'])->pluck('value'))
        ->values();

    $rules = (new GeneralSettingsRequest)->rules();

    // `timedatectl list-timezones` reports 497 zones to PHP's 419 — the extra
    // 78 are deprecated aliases the validator rejects. Offering one would give
    // the user a choice the API refuses, with nothing on screen saying why.
    // This test is what stops the two lists drifting apart later.
    $rejected = $offered->reject(
        fn (string $zone) => Validator::make(['timezone' => $zone], ['timezone' => $rules['timezone']])->passes()
    );

    expect($rejected->values()->all())->toBe([])
        ->and($offered)->toHaveCount(count(DateTimeZone::listIdentifiers()));
});

it('groups zones by region, with none empty', function () {
    $groups = collect(($this->get)('/api/timezones')->assertOk()->json('timezones'));

    expect($groups->pluck('region')->all())->toContain('Africa', 'America', 'Asia', 'Europe', 'Pacific')
        // 419 in one flat list is not a picker anybody can use.
        ->and($groups->every(fn (array $g) => count($g['zones']) > 0))->toBeTrue()
        // Sorted, so the frontend renders them in the order it receives them.
        ->and($groups->pluck('region')->all())->toBe($groups->pluck('region')->sort()->values()->all());
});

it('reports the current offset, including the half-hour ones', function () {
    $zones = collect(($this->get)('/api/timezones')->json('timezones'))
        ->flatMap(fn (array $group) => $group['zones'])
        ->keyBy('value');

    // India is +05:30 year round — a picker that only handles whole hours
    // gets this wrong, and it is a large share of the user base.
    expect($zones['Asia/Kolkata']['offset'])->toBe('+05:30')
        ->and($zones['Asia/Kolkata']['offset_minutes'])->toBe(330)
        ->and($zones['Asia/Kolkata']['label'])->toBe('Kolkata');

    // Nepal is +05:45, and Chatham +12:45/+13:45 — the quarter-hour cases.
    expect($zones['Asia/Kathmandu']['offset'])->toBe('+05:45');
});

it('computes the offset live rather than freezing it', function () {
    $zones = collect(app(Timezones::class)->grouped())
        ->flatMap(fn (array $group) => $group['zones'])
        ->keyBy('value');

    // A zone that observes DST must match what PHP says right now, not a
    // stored value that is wrong for half the year.
    $tz = new DateTimeZone('Europe/London');
    $expected = intdiv($tz->getOffset(new DateTime('now', $tz)), 60);

    expect($zones['Europe/London']['offset_minutes'])->toBe($expected);
});

it('turns underscores into spaces in the label but not the value', function () {
    $zones = collect(app(Timezones::class)->grouped())
        ->flatMap(fn (array $group) => $group['zones'])
        ->keyBy('value');

    expect($zones['America/New_York']['label'])->toBe('New York')
        // The value is the IANA identifier and must go back unchanged.
        ->and($zones['America/New_York']['value'])->toBe('America/New_York');
});

it('keeps UTC, as its own region rather than dropping it', function () {
    $groups = collect(($this->get)('/api/timezones')->json('timezones'))->keyBy('region');

    // A single-segment identifier has no region to group under; hiding it
    // would lose the one zone a server is most likely to be set to.
    expect($groups->has('UTC'))->toBeTrue()
        ->and($groups['UTC']['zones'][0]['value'])->toBe('UTC')
        ->and($groups['UTC']['zones'][0]['offset'])->toBe('+00:00');
});

it('is available to any signed-in user, with no feature permission', function () {
    // Settings, cronjob schedules and backup windows all need this list.
    // Gating it on any one of those permissions hides it from the others.
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    expect($user->canView('setting'))->toBeFalse();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/timezones')
        ->assertOk()
        ->assertJsonStructure(['timezones' => [['region', 'zones' => [['value', 'label', 'offset', 'offset_minutes']]]]]);
});

it('is not open to the world', function () {
    $this->getJson('/api/timezones')->assertUnauthorized();
});
