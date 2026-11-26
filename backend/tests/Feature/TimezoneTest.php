<?php

use App\Http\Requests\Server\Application\SavePhpSettingsRequest;
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
    // Whatever GET hands you must be something PUT takes back. A fresh Debian box reports Etc/UTC,
    // which PHP's default identifier list omits (the backward-compatible group holds Etc/*).
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

    // PHP's default list has none of these; the OS ships them and defaults to one.
    expect($values)->toContain('Etc/UTC');
});

it('groups zones by region, with none empty', function () {
    $groups = collect(($this->get)('/api/timezones')->assertOk()->json('timezones'));

    expect($groups->pluck('region')->all())->toContain('Africa', 'America', 'Asia', 'Etc', 'Europe', 'Pacific')
        // Hundreds of zones in one flat list are not usable as a picker.
        ->and($groups->every(fn (array $g) => count($g['zones']) > 0))->toBeTrue()
        // Sorted, so the frontend renders them in the order it receives them.
        ->and($groups->pluck('region')->all())->toBe($groups->pluck('region')->sort()->values()->all());
});

it('reports the current offset, including the half-hour ones', function () {
    $zones = collect(($this->get)('/api/timezones')->json('timezones'))
        ->flatMap(fn (array $group) => $group['zones'])
        ->keyBy('value');

    // India is +05:30 year round: a picker that only handles whole hours gets it wrong.
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

    // A single-segment identifier has no region to group under, but hiding it would lose the zone
    // a server is most likely set to.
    expect($groups->has('UTC'))->toBeTrue()
        ->and($groups['UTC']['zones'][0]['value'])->toBe('UTC')
        ->and($groups['UTC']['zones'][0]['offset'])->toBe('+00:00');
});

it('is available to any signed-in user, with no feature permission', function () {
    // Settings, cronjob schedules and backup windows all need this list; gating it on one of those
    // permissions hides it from the others.
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

    // Only the changed field. The form submits all three every time and the commands do not share
    // a privilege level: running an untouched one can fail the request after an earlier one took effect.
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

it('offers the PHP settings only zones PHP accepts, and accepts every one (FS-B8)', function () {
    // The OS list carries names PHP's date.timezone refuses (US/Eastern) and
    // PHP's canonical list misses ones it accepts (Etc/UTC).
    Process::fake(fn () => Process::result(output: "Etc/UTC\nUS/Eastern\nEurope/Berlin\nEtc/GMT+5\n"));

    $offered = collect(($this->get)('/api/timezones?for=php')->assertOk()->json('timezones'))
        ->flatMap(fn (array $group) => collect($group['zones'])->pluck('value'))
        ->values();

    $rule = (new SavePhpSettingsRequest)->rules()['php_timezone'];

    expect($offered->sort()->values()->all())->toBe(['Etc/GMT+5', 'Etc/UTC', 'Europe/Berlin'])
        ->and($offered->reject(fn ($zone) => Validator::make(['php_timezone' => $zone], ['php_timezone' => $rule])->passes())->all())->toBe([]);
});
