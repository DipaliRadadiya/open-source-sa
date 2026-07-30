<?php

use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Models\User;
use App\Services\Server\Settings\SettingsManager;
use App\Services\Timezones;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('t')->plainTextToken;

    $this->get = fn (string $uri) => test()
        ->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson($uri);
});

it('accepts back every value it offers', function () {
    $offered = collect(app(Timezones::class)->grouped())
        ->flatMap(fn (array $group) => collect($group['zones'])->pluck('value'))
        ->values();

    $rules = (new GeneralSettingsRequest)->rules();

    $rejected = $offered->reject(
        fn (string $zone) => Validator::make(['timezone' => $zone], ['timezone' => $rules['timezone']])->passes()
    );

    expect($rejected->values()->all())->toBe([])
        ->and($offered)->not->toBeEmpty();
});

it('accepts the timezone the server is currently set to', function () {
    // The bug this is here for: the form loaded showing Etc/UTC — the real
    // setting on a fresh Debian box — and then refused to save it, because
    // the validator used PHP's default identifier list, which omits the
    // backward-compatible group Etc/* lives in. You could not submit the
    // form without changing a field you had not come to change.
    //
    // The rule this encodes is general: whatever GET hands you must be
    // something PUT will take back.
    $current = app(SettingsManager::class)->find('general')->read()['timezone'];

    expect(app(Timezones::class)->accepts($current))->toBeTrue(
        "the server's own timezone ({$current}) is not an accepted value"
    );

    $rules = (new GeneralSettingsRequest)->rules();

    expect(Validator::make(['timezone' => $current], ['timezone' => $rules['timezone']])->passes())->toBeTrue();
});

it('offers the backward-compatible names the OS actually uses', function () {
    $values = collect(app(Timezones::class)->grouped())
        ->flatMap(fn (array $group) => collect($group['zones'])->pluck('value'));

    // PHP's default list has zero of these; the OS ships 35 and defaults to
    // one of them.
    expect($values)->toContain('Etc/UTC');
});

it('groups zones by region, with none empty', function () {
    $groups = collect(($this->get)('/api/timezones')->assertOk()->json('timezones'));

    expect($groups->pluck('region')->all())->toContain('Africa', 'America', 'Asia', 'Etc', 'Europe', 'Pacific')
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

it('does not touch the OS for fields that did not change', function () {
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return match (true) {
            in_array('--property=Timezone', $process->command, true) => Process::result(output: "Etc/UTC\n"),
            in_array('--property=NTP', $process->command, true) => Process::result(output: "yes\n"),
            in_array('--static', $process->command, true) => Process::result(output: "web-01\n"),
            default => Process::result(exitCode: 0),
        };
    });

    app(SettingsManager::class)->find('general')->apply([
        'timezone' => 'Asia/Kolkata',
        'hostname' => 'web-01',      // unchanged
        'ntp' => true,               // unchanged
    ]);

    $written = collect($runs)->filter(fn (array $c) => in_array('set-timezone', $c, true)
        || in_array('set-hostname', $c, true)
        || in_array('set-ntp', $c, true));

    // Only the changed field. The form submits all three every time, and
    // these commands do not share a privilege level — running an untouched
    // one can fail the whole request, after an earlier one already took
    // effect.
    expect($written->values()->all())->toBe([['timedatectl', 'set-timezone', 'Asia/Kolkata']]);
});

it('writes nothing at all when nothing changed', function () {
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return match (true) {
            in_array('--property=Timezone', $process->command, true) => Process::result(output: "Etc/UTC\n"),
            in_array('--property=NTP', $process->command, true) => Process::result(output: "yes\n"),
            in_array('--static', $process->command, true) => Process::result(output: "web-01\n"),
            default => Process::result(exitCode: 0),
        };
    });

    app(SettingsManager::class)->find('general')->apply([
        'timezone' => 'Etc/UTC',
        'hostname' => 'web-01',
        'ntp' => true,
    ]);

    // Saving a form you opened and did not edit must not be able to fail.
    expect(collect($runs)->filter(fn (array $c) => str_contains(implode(' ', $c), 'set-'))->all())->toBe([]);
});
