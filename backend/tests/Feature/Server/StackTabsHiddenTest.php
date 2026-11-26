<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Server\Capabilities\ServerCapabilities;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Collection;

/**
 * What a Docker box does not do, and therefore does not show.
 *
 * `--stack=docker` serves containers only, so the PHP versions, ini and extensions screens are
 * pointless there. Hiding the tab is not enough: the endpoints must refuse too, or the route
 * still works behind a hidden button (see `EnsureServerManagesDatabases`).
 *
 * The case that matters most is a server with no recorded capability row: migrated in from
 * another panel and serving PHP. Refusing it the PHP screen would be worse than one extra tab
 * on a Docker box.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function dockerStackAs(string $stack, array $profiles): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => $stack,
        'web_server' => 'nginx',
        'capabilities' => [
            // True on a Docker box too: PHP is installed because the panel is a Laravel app. The
            // assertions below are about `serving_profiles`.
            'php' => true,
            'node' => false,
            'serving_profiles' => $profiles,
        ],
        'source' => 'installer',
        'verified_at' => now(),
    ]);
}

function phpTabHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** Every server-level tab this user would be shown. */
function serverTabs(): Collection
{
    return collect(
        test()->withHeaders(phpTabHeaders())->getJson('/api/permissions?level=server')->json('permissions')
    )->pluck('name');
}

/** Is `php` in the server-level menu this user would be shown? */
function phpInSidebar(): bool
{
    $response = test()->withHeaders(phpTabHeaders())->getJson('/api/permissions?level=server');

    return collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'php');
}

/* The sidebar. */

it('hides the PHP tab on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    expect(phpInSidebar())->toBeFalse();
});

it('keeps the PHP tab on every stack that serves PHP sites', function (string $stack) {
    dockerStackAs($stack, ['php', 'static']);

    expect(phpInSidebar())->toBeTrue();
})->with(['lemp', 'lamp', 'ols']);

it('keeps the PHP tab on a server with no recorded capabilities', function () {
    // The migrated-in box: `ServerCapabilities::DEFAULT_PROFILES` is permissive on purpose.
    ServerCapability::query()->delete();

    expect(phpInSidebar())->toBeTrue();
});

it('hides it from the unfiltered menu too, not only from level=server', function () {
    // The sidebar asks with a level; other callers do not. A filter on only one would hide the tab
    // while page gates still say the user may view it.
    dockerStackAs('docker', ['docker']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/permissions');

    expect(collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'php'))
        ->toBeFalse();
});

it('hides the Databases tab on a Docker server', function () {
    // An application there brings its own database container, so the panel manages no engine
    // and these endpoints already answer 409.
    dockerStackAs('docker', ['docker']);

    expect(serverTabs())->not->toContain('database');
});

it('keeps the Databases tab wherever an engine is managed', function (string $stack, array $profiles) {
    dockerStackAs($stack, $profiles);

    expect(serverTabs())->toContain('database');
})->with([
    ['lemp', ['php', 'static']],
    ['mern', ['node', 'static']],
]);

it('keeps the Databases tab on a server with no recorded capabilities', function () {
    ServerCapability::query()->delete();

    expect(serverTabs())->toContain('database');
});

it('hides the Node.js tab on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    expect(serverTabs())->not->toContain('node');
});

it('KEEPS the Node.js tab on a LEMP box, even one recorded without Node sites', function () {
    // `hosts('node')` can be false for a recorded LEMP row, but hiding Node on that basis would
    // break every Laravel site that builds its assets with it (the panel's own `build_command`
    // placeholder is `npm ci && npm run build`). A Docker box differs: it runs no sites at all.
    dockerStackAs('lemp', ['php', 'static']);

    expect(serverTabs())->toContain('node');
});

it('keeps the Node endpoints answering on a LEMP box', function () {
    // The same point at the API: the middleware could use the wrong predicate as easily as the sidebar.
    dockerStackAs('lemp', ['php', 'static']);

    $this->withHeaders(phpTabHeaders())->getJson('/api/node')->assertStatus(200);
});

it('refuses the Node endpoints on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    foreach ([
        ['getJson', '/api/node'],
        ['putJson', '/api/node/default'],
        ['postJson', '/api/node/versions'],
    ] as [$method, $url]) {
        $this->withHeaders(phpTabHeaders())->{$method}($url, [])->assertStatus(409);
    }
});

it('has the Node refusal translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('errors/node.not_a_node_server', [], $locale);

        expect($line)->not->toBe('errors/node.not_a_node_server')->and($line)->not->toBeEmpty();
    }
});

it('asks one source for whether databases are managed', function () {
    // The middleware and the sidebar must share one rule, or a fix lands in only one copy.
    dockerStackAs('docker', ['docker']);
    expect(app(ServerCapabilities::class)->managesDatabases())->toBeFalse();

    dockerStackAs('lemp', ['php', 'static']);
    expect(app(ServerCapabilities::class)->managesDatabases())->toBeTrue();
});

/* The endpoints. Hiding without gating is the worse state. */

it('refuses every PHP endpoint on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    foreach ([
        ['getJson', '/api/php'],
        ['putJson', '/api/php/default'],
        ['postJson', '/api/php/versions'],
        ['getJson', '/api/php/versions/8.4/ini'],
        ['getJson', '/api/php/versions/8.4/extensions'],
        ['getJson', '/api/php/versions/8.4/ioncube'],
    ] as [$method, $url]) {
        $this->withHeaders(phpTabHeaders())->{$method}($url, [])->assertStatus(409);
    }
});

it('says why, rather than answering 409 with nothing', function () {
    dockerStackAs('docker', ['docker']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/php')->assertStatus(409);

    expect($response->json('message'))->toBe(__('errors/php.not_a_php_server'))
        // The sentence must name the panel's own PHP, or the obvious question is how the panel runs.
        ->and($response->json('message'))->toContain('panel');
});

it('does not refuse them on a LEMP server', function () {
    dockerStackAs('lemp', ['php', 'static']);

    // Anything other than the capability refusal (409) is what matters here.
    $this->withHeaders(phpTabHeaders())->getJson('/api/php')->assertStatus(200);
});

it('has the refusal translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('errors/php.not_a_php_server', [], $locale);

        expect($line)->not->toBe('errors/php.not_a_php_server')->and($line)->not->toBeEmpty();
    }
});

/*
 * Registry credentials are an integration, not a corner of the Docker screen: private-image
 * support must be discoverable.
 */

it('offers Docker Registries in the integrations section', function () {
    dockerStackAs('docker', ['docker']);

    $items = collect(
        test()->withHeaders(phpTabHeaders())->getJson('/api/permissions?level=server')->json('permissions')
    );

    $registry = $items->firstWhere('name', 'registry');

    expect($registry)->not->toBeNull()
        // `sub_level` makes the sidebar draw it under Integrations beside Git and Storage.
        ->and($registry['sub_level'])->toBe('integration')
        ->and($registry['url'])->toBe('/integrations/registries');
});

it('is its own permission, not a corner of docker', function () {
    // `docker` manages networks and volumes; `registry` stores a credential that can pull private
    // code onto the box. Sharing one grant would give away the second to get the first.
    dockerStackAs('docker', ['docker']);

    $role = Role::create(['name' => 'Netops', 'slug' => 'netops']);
    $role->permissions()->attach(
        Permission::where('name', 'docker')->sole()->id,
        ['view' => true, 'manage' => true],
    );

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    // Full control of Docker's own objects...
    $this->withHeaders($headers)->getJson('/api/docker/networks')->assertOk();

    // ...and no reach into the credentials.
    $this->withHeaders($headers)->getJson('/api/integrations/registries')->assertForbidden();
});

it('hides Docker Registries on a server that hosts no containers', function () {
    // The endpoints refuse there too, so the tab and the routes agree.
    dockerStackAs('lemp', ['php', 'static']);

    expect(serverTabs())->not->toContain('registry');

    $this->withHeaders(phpTabHeaders())->getJson('/api/integrations/registries')->assertStatus(409);
});

it('has the sidebar label translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('nav.registry', [], $locale);

        expect($line)->not->toBe('nav.registry')->and($line)->not->toBeEmpty();
    }
});

it('hides the Docker tab on every stack that serves no containers', function (string $stack, array $profiles) {
    // `EnsureServerHostsContainers` already answers 409 on every Docker route off a container
    // stack, so the menu must not offer a page whose every request fails.
    dockerStackAs($stack, $profiles);

    expect(serverTabs())->not->toContain('docker');
})->with([
    ['lemp', ['php', 'static']],
    ['lamp', ['php', 'static']],
    ['ols', ['php', 'static']],
    ['mern', ['node', 'static']],
]);

it('keeps the Docker tab on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    expect(serverTabs())->toContain('docker')
        // Its registry companion is gated on the same answer.
        ->and(serverTabs())->toContain('registry');
});

it('keeps the Docker tab on a server with no recorded capabilities', function () {
    // Unlike PHP, `DEFAULT_PROFILES` does not include `docker`: a box with no row is not a Docker
    // box, and the endpoints would 409 for it too.
    ServerCapability::query()->delete();

    expect(serverTabs())->not->toContain('docker');
});

it('hides the Docker tab from the unfiltered menu too', function () {
    // Same reason as the PHP case: a filter on only the levelled call would leave page gates saying
    // the user may view the tab.
    dockerStackAs('lemp', ['php', 'static']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/permissions');

    expect(collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'docker'))
        ->toBeFalse();
});
