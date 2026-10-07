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
 * `--stack=docker` serves containers and nothing else, so versions, ini files and
 * extensions are a screen about nothing there. Reported as "hide the PHP tab",
 * which is half of it: hiding the tab while the seventeen endpoints still answer
 * is the state `EnsureServerManagesDatabases` warns about in its own docblock — a
 * hidden button whose route works, that nobody is looking for.
 *
 * The case these tests exist for is the THIRD one, not the first two: a server
 * with no recorded capability row. Those are real, migrated in from another panel,
 * and certainly serving PHP. Refusing them their PHP screen because nobody wrote a
 * row would be a far worse bug than one extra tab on a Docker box.
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
            // True on a Docker box too, and deliberately: PHP IS installed,
            // because the panel is a Laravel application. Every assertion below
            // is about `serving_profiles`, which is the different question.
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

/*
 * The sidebar.
 */

it('hides the PHP tab on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    expect(phpInSidebar())->toBeFalse();
});

it('keeps the PHP tab on every stack that serves PHP sites', function (string $stack) {
    dockerStackAs($stack, ['php', 'static']);

    expect(phpInSidebar())->toBeTrue();
})->with(['lemp', 'lamp', 'ols']);

it('keeps the PHP tab on a server with no recorded capabilities', function () {
    // The migrated-in box. `ServerCapabilities::DEFAULT_PROFILES` is permissive on
    // purpose and this is the assertion that keeps it that way.
    ServerCapability::query()->delete();

    expect(phpInSidebar())->toBeTrue();
});

it('hides it from the unfiltered menu too, not only from level=server', function () {
    // The sidebar asks with a level; other callers do not. A filter that only
    // applied to one of them would hide the tab and leave every page gate saying
    // the user may still view it.
    dockerStackAs('docker', ['docker']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/permissions');

    expect(collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'php'))
        ->toBeFalse();
});

it('hides the Databases tab on a Docker server', function () {
    // An application that wants a database there brings one as a container, so the
    // panel manages no engine — and these endpoints already answered 409, which
    // made this the one tab that was visibly broken rather than merely pointless.
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
    // The assertion this whole rule was designed around. `hosts('node')` is false
    // for this row (a LEMP preset no longer looks like this — it hosts Node sites
    // since 2026-10-05 — but a recorded row can), and hiding the screen on that
    // basis would take Node away from every Laravel site that builds its assets
    // with it. The panel's own build_command placeholder is `npm ci && npm run
    // build`. What makes a Docker box different is that it runs no sites at all.
    dockerStackAs('lemp', ['php', 'static']);

    expect(serverTabs())->toContain('node');
});

it('keeps the Node endpoints answering on a LEMP box', function () {
    // The same point at the API, because the middleware could have been written
    // with the wrong predicate just as easily as the sidebar.
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

// The test that used to live here asserted the opposite — that the Docker tab
// stayed on a LEMP box, because hiding it was "a decision about a different
// stack and nobody has asked". Somebody asked (2026-10-07), so the decision
// changed and the assertion moved with it, down with the other three stack
// filters. Recorded rather than quietly deleted: it was a deliberate choice,
// not an oversight, and the next person to wonder why should find the answer.

it('has the Node refusal translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('errors/node.not_a_node_server', [], $locale);

        expect($line)->not->toBe('errors/node.not_a_node_server')->and($line)->not->toBeEmpty();
    }
});

it('asks one source for whether databases are managed', function () {
    // The rule was `foreach (['php','node'])` inside the middleware, and the
    // sidebar needed the same answer. Two copies of a two-line rule is one copy
    // that gets fixed, so both now call this.
    dockerStackAs('docker', ['docker']);
    expect(app(ServerCapabilities::class)->managesDatabases())->toBeFalse();

    dockerStackAs('lemp', ['php', 'static']);
    expect(app(ServerCapabilities::class)->managesDatabases())->toBeTrue();
});

/*
 * The endpoints. Hiding without gating is the worse state.
 */

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
        // The sentence has to name the PANEL's own PHP, or the first question back
        // is "so how is the panel running?"
        ->and($response->json('message'))->toContain('panel');
});

it('does not refuse them on a LEMP server', function () {
    dockerStackAs('lemp', ['php', 'static']);

    // Not asserting 200: the endpoint shells out to the box and this suite has no
    // PHP versions installed. Anything other than the capability refusal is what
    // matters here.
    $this->withHeaders(phpTabHeaders())->getJson('/api/php')->assertStatus(200);
});

it('has the refusal translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('errors/php.not_a_php_server', [], $locale);

        expect($line)->not->toBe('errors/php.not_a_php_server')->and($line)->not->toBeEmpty();
    }
});

/*
 * Registry credentials as an integration, not a corner of the Docker screen.
 *
 * Reported as "there is no proper instruction, bad UX", and the placement was half
 * of that: the card sat below networks and volumes, so the panel's only
 * private-image support was something you had to already know about to find.
 */

it('offers Docker Registries in the integrations section', function () {
    dockerStackAs('docker', ['docker']);

    $items = collect(
        test()->withHeaders(phpTabHeaders())->getJson('/api/permissions?level=server')->json('permissions')
    );

    $registry = $items->firstWhere('name', 'registry');

    expect($registry)->not->toBeNull()
        // The sub_level is what makes the sidebar draw it under Integrations
        // beside Git and Storage, rather than loose among the server screens.
        ->and($registry['sub_level'])->toBe('integration')
        ->and($registry['url'])->toBe('/integrations/registries');
});

it('is its own permission, not a corner of docker', function () {
    // The two grants mean different things: `docker` manages networks and volumes
    // on this box, `registry` stores a credential that can pull private code onto
    // it. Sharing one would mean granting the second to get the first.
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
    // A credential for pulling container images is nothing on a box that runs
    // none — and the endpoints refuse there too, so the tab and the routes agree.
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
    // The last of the four, and the one that was visibly wrong rather than
    // merely pointless: `EnsureServerHostsContainers` already answered 409 on
    // all seventeen Docker routes off a container stack, so the menu offered a
    // page whose every request failed. Networks, volumes and containerised
    // databases are all about containers; a box with none has nothing to show.
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
        // And its registry companion, which was already gated on the same answer.
        ->and(serverTabs())->toContain('registry');
});

it('keeps the Docker tab on a server with no recorded capabilities', function () {
    // The migrated-in box again. `DEFAULT_PROFILES` does not include `docker`,
    // so this one genuinely does hide — unlike PHP above, where the permissive
    // default is the whole point. Asserted so the difference is deliberate and
    // not a surprise later: a box with no row is not a Docker box, and the
    // endpoints would 409 for it too.
    ServerCapability::query()->delete();

    expect(serverTabs())->not->toContain('docker');
});

it('hides the Docker tab from the unfiltered menu too', function () {
    // Same reason as the PHP case: the sidebar asks with a level, other callers
    // do not, and a filter that applied to only one would hide the tab while
    // every page gate still said the user may view it.
    dockerStackAs('lemp', ['php', 'static']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/permissions');

    expect(collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'docker'))
        ->toBeFalse();
});
