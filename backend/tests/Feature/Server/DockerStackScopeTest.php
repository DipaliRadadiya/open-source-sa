<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\HostCpus;
use App\Services\Server\Setup\SetupCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/*
 * A Docker server hosts containers and nothing else.
 *
 * `capabilities.php` answers **"is PHP installed"**, which is true on every box because the panel
 * is a Laravel app; the setup catalogue must ask **"may this box host PHP sites"** instead.
 * Hence `serving_profiles`, recorded per stack and never overwritten by detection.
 */

function recordStack(string $stack): void
{
    ServerCapability::query()->delete();
    app(ServerCapabilities::class)->recordStack($stack);
}

it('keeps php installed and php sites unhosted, which are different answers', function () {
    recordStack('docker');

    $capabilities = app(ServerCapabilities::class);

    // PHP really is on the box, because the panel needs it; reporting false would contradict the
    // PHP screen and the doctor.
    expect($capabilities->current()->can('php'))->toBeTrue()
        // And yet no PHP site may be built.
        ->and($capabilities->hosts('php'))->toBeFalse()
        ->and($capabilities->hosts('docker'))->toBeTrue();
});

it('records the right profiles for every stack', function () {
    foreach ([
        // Node too: n8n, Node-RED, Uptime Kuma and NodeBB run on these stacks.
        'lemp' => ['php', 'node', 'static'],
        'lamp' => ['php', 'node', 'static'],
        'ols' => ['php', 'node', 'static'],
        'mern' => ['node', 'static'],
        'docker' => ['docker'],
    ] as $stack => $expected) {
        recordStack($stack);

        expect(app(ServerCapabilities::class)->servingProfiles())
            ->toEqualCanonicalizing($expected, "stack {$stack}");
    }
});

it('does not lose the decision when a runtime is installed', function () {
    // `refresh()` runs on every runtime install/removal; overwriting the whole array with
    // `detectRuntimes()` would wipe `serving_profiles` and make a Docker box offer WordPress again.
    recordStack('docker');

    app(ServerCapabilities::class)->refresh();

    expect(app(ServerCapabilities::class)->hosts('php'))->toBeFalse()
        ->and(app(ServerCapabilities::class)->hosts('docker'))->toBeTrue();
});

it('is permissive about a server nobody recorded', function () {
    // A box migrated in from another panel has `stack = null` and is hosting something;
    // answering "hosts nothing" would empty the catalogue on a working server.
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => null,
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'detected',
    ]);

    expect(app(ServerCapabilities::class)->hosts('php'))->toBeTrue()
        ->and(app(ServerCapabilities::class)->hosts('node'))->toBeTrue();
});

it('leaves types this stack will never host out of the grid', function () {
    // Not shown: nothing installs a way out, and seventeen cards repeating one sentence about the
    // server is noise.
    recordStack('docker');

    $names = collect(app(SiteTypeManager::class)->catalog())->pluck('name');

    expect($names)->not->toContain('wordpress')
        ->and($names)->not->toContain('moodle');
});

it('still refuses a filtered type at the API', function () {
    // Hiding a card is only acceptable while the endpoint says no; otherwise it is a hidden door.
    recordStack('docker');

    $manager = app(SiteTypeManager::class);
    $wordpress = collect($manager->all())->first(fn ($type) => $type->name() === 'wordpress');

    expect($manager->unavailable($wordpress))->not->toBeNull()
        ->and($manager->unavailable($wordpress)['code'])->toBe(SiteTypeManager::BLOCKED_STACK)
        ->and($manager->unavailable($wordpress)['reason'])->toBe(__('application.unavailable.stack'));
});

it('still offers PHP site types on a PHP stack', function () {
    // Guards against over-gating: only the wrong stacks may be filtered.
    recordStack('lemp');

    $wordpress = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'wordpress');

    // Present AND not stack-blocked: the filter must not eat the grid on a PHP stack.
    expect($wordpress)->not->toBeNull()
        ->and($wordpress['unavailable_code'])->not->toBe(SiteTypeManager::BLOCKED_STACK);
});

it('leaves the catalog a list on every stack, whichever end it filtered', function (string $stack) {
    // The filter drops a contiguous run of types, and which end depends on the stack (a Docker
    // box keeps the leading container types, every other stack drops them), so this is asserted
    // per stack. A gapped array is a JSON object, and the frontend's `z.array()` would reject it.
    recordStack($stack);

    expect(array_is_list(app(SiteTypeManager::class)->catalog()))->toBeTrue();
})->with(['lemp', 'lamp', 'ols', 'mern', 'docker']);

it('leaves the catalog a list on a server with no recorded stack', function () {
    // The migrated-in box falls on `DEFAULT_PROFILES`: PHP and Node, no containers, so it filters
    // the Docker types like a LEMP box does.
    ServerCapability::query()->delete();

    expect(array_is_list(app(SiteTypeManager::class)->catalog()))->toBeTrue();
});

it('leaves the site-facing setup rows off a container-only server', function () {
    // `recommended => false` would leave the database row on the page with its install button:
    // `recommended` only decides whether setup counts as complete. The row has to be absent.
    recordStack('docker');

    $keys = collect(app(SetupCatalog::class)->toArray()['components'])->pluck('key');

    expect($keys)->not->toContain('database')
        // Extra PHP versions, extra Node versions and the compiler toolchain exist for hosted
        // sites; a container builds its dependencies inside its own image.
        ->and($keys)->not->toContain('php')
        ->and($keys)->not->toContain('node')
        ->and($keys)->not->toContain('build_tools')
        // What remains is what serves the panel and the server itself.
        ->and($keys)->toContain('redis')
        ->and($keys)->toContain('fail2ban');
});

it('keeps every setup row on a stack that hosts sites', function () {
    // Guards against over-filtering: only the container-only stack drops rows.
    recordStack('lemp');

    $keys = collect(app(SetupCatalog::class)->toArray()['components'])->pluck('key');

    foreach (['database', 'php', 'build_tools', 'redis', 'fail2ban'] as $key) {
        expect($keys)->toContain($key);
    }
});

it('refuses the database API, not merely the screen', function () {
    // Hiding the Databases screen while every endpoint still answers is worse than showing it.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    recordStack('docker');

    $this->actingAs($admin)->getJson('/api/databases')->assertStatus(409);
    $this->actingAs($admin)->getJson('/api/databases/engines')->assertStatus(409);
});

it('allows the database API on a stack that hosts database-backed apps', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    recordStack('lemp');

    $this->actingAs($admin)->getJson('/api/databases')->assertOk();
});

it('translates both refusals in every locale', function () {
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        expect(__('errors/server.databases_not_managed', [], $locale))
            ->not->toBe('errors/server.databases_not_managed', "server refusal missing in {$locale}");

        expect(__('application.unavailable.stack', [], $locale))
            ->not->toBe('application.unavailable.stack', "site-type refusal missing in {$locale}");
    }
});

/*
 * The network field on the Docker card.
 *
 * A `select` declared with no options makes the create form fall back to the runtime version
 * list and offer PHP versions as networks. The options are resolved here, which costs a
 * `docker network ls` that must not run on a box that hosts no containers.
 */

it('offers the server\'s networks as options on the Docker card', function () {
    recordStack('docker');

    Process::fake(fn () => Process::result(output: implode("\n", [
        json_encode(['ID' => 'a1', 'Name' => 'bridge', 'Driver' => 'bridge', 'Scope' => 'local']),
        json_encode(['ID' => 'a2', 'Name' => 'ghost-net', 'Driver' => 'bridge', 'Scope' => 'local']),
    ])));

    $docker = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'docker');
    $field = collect($docker['fields'])->firstWhere('name', 'docker_network');

    expect($field)->not->toBeNull()
        ->and($field['type'])->toBe('select')
        // Never empty when networks exist, or the form renders a version list.
        ->and(collect($field['options'])->pluck('value')->all())->toBe(['ghost-net'])
        // `bridge` is what an empty choice already means, and `host`/`none` cannot resolve names.
        ->and(collect($field['options'])->pluck('value'))->not->toContain('bridge');
});

it('does not ask docker anything on a server that hosts no containers', function () {
    // `fields()` is serialised for EVERY site type in the catalog listing; ungated, it would run
    // `docker network ls` on a LEMP box to build options for a card that is not shown.
    recordStack('lemp');

    Process::fake();

    app(SiteTypeManager::class)->catalog();

    Process::assertNotRan(fn ($process) => in_array('docker', $process->command, true));
});

it('offers the create-new fields on the Docker card', function () {
    // The form renders what the API declares, so an undeclared field does not exist to the user.
    recordStack('docker');

    Process::fake(fn () => Process::result(output: ''));

    $docker = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'docker');
    $names = collect($docker['fields'])->pluck('name');

    expect($names)->toContain('docker_network')
        ->and($names)->toContain('docker_network_new')
        ->and($names)->toContain('volume_new')
        ->and($names)->toContain('volume_path');

    // All advanced: the common case is one container that talks to nobody and keeps nothing.
    foreach (['docker_network', 'docker_network_new', 'volume_new', 'volume_path'] as $field) {
        expect(collect($docker['fields'])->firstWhere('name', $field)['advanced'])->toBeTrue();
    }

    // Every one is labelled: `field()` reads `application.fields.<name>`, and a missing key
    // renders the raw key path.
    foreach (['docker_network_new', 'volume_new', 'volume_path'] as $field) {
        $label = collect($docker['fields'])->firstWhere('name', $field)['label'];
        expect($label)->not->toContain('application.fields');
    }
});

it('makes the Docker card a choice between two modes, not both at once', function () {
    // Simple-mode fields and the compose textarea are alternatives, so the form asks which mode
    // first instead of showing everything at once.
    recordStack('docker');

    Process::fake(fn () => Process::result(output: ''));

    $docker = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'docker');
    $fields = collect($docker['fields'])->keyBy('name');

    $mode = $fields->get('docker_mode');

    expect($mode)->not->toBeNull()
        ->and($mode['type'])->toBe('select')
        // Not advanced: it decides which other fields exist.
        ->and($mode['advanced'])->toBeFalse()
        ->and(collect($mode['options'])->pluck('value')->all())->toBe(['simple', 'compose']);

    // Every other field declares its mode, or it is shown in both.
    foreach (['image', 'container_port', 'docker_network', 'docker_network_new', 'volume_new', 'volume_path'] as $name) {
        expect($fields->get($name)['depends_on'] ?? null)->toBe('docker_mode:simple', "{$name} is not gated");
    }

    expect($fields->get('compose')['depends_on'] ?? null)->toBe('docker_mode:compose')
        // Not behind Advanced: in compose mode it IS the field.
        ->and($fields->get('compose')['advanced'])->toBeFalse();
});

it('stores the mode nowhere, because the site already records how it was made', function () {
    // A "made in compose mode" column would be a second source for a fact the compose file
    // already carries, free to drift from it.
    expect((new Application)->getFillable())->not->toContain('docker_mode');

    $create = file_get_contents(base_path('app/Actions/Server/Application/CreateApplication.php'));

    // Named as a deliberate exception, as the "every declared field is handled" guard requires.
    expect($create)->toContain("\$formOnly = ['docker_mode']");
});

it('offers the size fields in both modes, and not behind Advanced', function () {
    // Two decisions pinned here that look inconsistent next to the fields around them:
    //
    // **No `depends_on`.** The compose override applies `mem_limit` and `cpus` to every service in
    // a PASTED file too, so gating on `docker_mode:simple` would remove sizing from compose mode.
    // `registry_id` is ungated for the same reason.
    //
    // **Not advanced**, unlike the network and volume fields: a size is the first thing anyone
    // deploying a container looks for.
    recordStack('docker');

    Process::fake(fn () => Process::result(output: ''));

    $docker = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'docker');
    $fields = collect($docker['fields'])->keyBy('name');

    foreach (['memory_limit', 'cpu_limit'] as $name) {
        $field = $fields->get($name);

        expect($field)->not->toBeNull("{$name} is not offered on the create form")
            ->and($field['advanced'])->toBeFalse("{$name} is hidden behind Advanced")
            ->and($field['depends_on'] ?? null)->toBeNull("{$name} is gated to one mode")
            // No default on either: a pre-filled value would cap every site made through this form.
            ->and($field['default'] ?? null)->toBeNull("{$name} arrives pre-filled");
    }

    // The CPU help names the host's core count, the actual bound, rather than describing the rule.
    expect($fields->get('cpu_limit')['help'])->toContain((string) app(HostCpus::class)->count());
});

it('offers the one-click Node applications on LEMP, LAMP and OLS, and on no Docker server', function (string $stack, bool $offered) {
    recordStack($stack);

    $names = collect(app(SiteTypeManager::class)->catalog())->pluck('name');

    foreach (['n8n', 'nodered', 'uptimekuma', 'nodebb'] as $type) {
        expect($names->contains($type))->toBe($offered, "{$type} on {$stack}");
    }
})->with([
    ['lemp', true], ['lamp', true], ['ols', true], ['mern', true], ['docker', false],
]);

it('sends the catalog as a JSON list on every stack', function (string $stack) {
    // `array_filter` keeps keys; a gapped array is encoded as a JSON object, which the create
    // page cannot read.
    recordStack($stack);

    expect(array_is_list(app(SiteTypeManager::class)->catalog()))->toBeTrue();
})->with(['lemp', 'lamp', 'ols', 'mern', 'docker']);

it('says "containers only" on a Docker server and nowhere else', function () {
    recordStack('docker');
    $wordpress = app(SiteTypeManager::class)->find('wordpress');

    expect(app(SiteTypeManager::class)->unavailable($wordpress)['reason'])->toBe(__('application.unavailable.stack'));

    recordStack('mern');
    app()->forgetInstance(ServerCapabilities::class);
    app()->forgetInstance(SiteTypeManager::class);

    expect(app(SiteTypeManager::class)->unavailable($wordpress)['reason'])->toBe(__('application.unavailable.stack_profile'));
});

it('has both refusals in every locale', function (string $locale) {
    foreach (['stack_profile', 'profile'] as $key) {
        expect(__("application.unavailable.{$key}", [], $locale))->not->toBe("application.unavailable.{$key}", "{$key} missing in {$locale}");
    }
})->with(['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi']);
