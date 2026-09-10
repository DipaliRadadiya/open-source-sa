<?php

use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Whether a card is offered depends on an engine the application can actually
 * use.
 *
 * `missingEngines()` used to return early for anything accepting MySQL or
 * MariaDB — nearly the whole catalog — so only NodeBB was ever checked. On a
 * MongoDB-only server every SQL-backed type reported itself available, took a
 * filled-in form, and failed at provisioning with `no-database-engine`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);
});

/**
 * Answer as a server with only `$engines` reachable.
 *
 * The client binary in the command is what says which engine is being asked:
 * `available()` is a live query through ServerOps either way, so a server
 * without MySQL is one where the `mysql` client's query does not succeed.
 */
function onlyEngines(array $engines): void
{
    // Counted in the fake rather than read back from `Process::recorded()`,
    // which is not reachable through the facade here.
    $GLOBALS['engineProbes'] = [];

    Process::fake(function ($process) use ($engines) {
        $command = implode(' ', (array) $process->command);

        foreach (['mysql' => 'mysql', 'mariadb' => 'mariadb', 'mongodb' => 'mongosh', 'postgresql' => 'psql'] as $engine => $client) {
            if (str_contains($command, $client)) {
                $GLOBALS['engineProbes'][] = $engine;

                return in_array($engine, $engines, true)
                    ? Process::result(output: '1')
                    : Process::result(exitCode: 1, errorOutput: 'command not found');
            }
        }

        return Process::result(output: '');
    });
}

/**
 * Prefixed because Pest helpers share one global namespace across the whole
 * suite: a bare catalog() collides with the permissions one and takes the
 * entire run down with a fatal, not a failed test.
 *
 * @return array<string, array<string, mixed>> keyed by type name
 */
function siteTypeCatalog(): array
{
    return collect(
        test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
            ->getJson('/api/site-types')
            ->assertOk()
            ->json('site_types')
    )->keyBy('name')->all();
}

it('greys a SQL-backed type on a server with only MongoDB', function () {
    onlyEngines(['mongodb']);

    $types = siteTypeCatalog();

    expect($types['wordpress']['available'])->toBeFalse()
        ->and($types['wordpress']['unavailable_code'])->toBe(SiteTypeManager::BLOCKED_DATABASE)
        // Named so the sentence can say what to install, rather than "a
        // database" — the whole point of the block is that it is actionable.
        ->and($types['wordpress']['unavailable_reason'])->toContain('MySQL');
});

it('offers a MongoDB type on that same server', function () {
    // The other half of the same answer: greying everything would be as wrong
    // as greying nothing, and this is the type the old check did handle.
    onlyEngines(['mongodb']);

    expect(siteTypeCatalog()['nodebb']['available'])->toBeTrue();
});

it('offers SQL-backed types when MySQL answers', function () {
    onlyEngines(['mysql']);

    $types = siteTypeCatalog();

    expect($types['wordpress']['available'])->toBeTrue()
        ->and($types['nodebb']['available'])->toBeFalse();
});

it('accepts either of the engines a type lists', function () {
    // MariaDB alone is a MySQL-compatible server. A type accepting both must
    // not be refused because the first name in its list is absent.
    onlyEngines(['mariadb']);

    expect(siteTypeCatalog()['wordpress']['available'])->toBeTrue();
});

it('leaves a type that needs no database alone when nothing is installed', function () {
    // The check must not become "this server has a database engine". A static
    // site does not care, and greying it would hide the catalog for the exact
    // reason the old early return worried about.
    onlyEngines([]);

    expect(siteTypeCatalog()['php']['available'])->toBeTrue();
});

it('probes each engine once however many types ask about it', function () {
    // Not an optimisation. `available()` is a live query through sudo, and the
    // check that used to run for one type now runs for eleven — unmemoized
    // that is dozens of subprocesses to render a grid of cards.
    onlyEngines(['mysql']);

    siteTypeCatalog();

    $probes = collect($GLOBALS['engineProbes']);

    // One per distinct engine, not one per type that asks.
    expect($probes->count())->toBe($probes->unique()->count());
});

it('publishes the engines each type accepts', function () {
    onlyEngines(['mysql']);

    $types = siteTypeCatalog();

    expect($types['wordpress']['accepted_engines'])->toContain('mysql')
        // MongoDB first: the first available engine wins, so a server
        // with both keeps making Mongo-backed forums.
        ->and($types['nodebb']['accepted_engines'])->toBe(['mongodb', 'postgresql'])
        // Empty rather than null for a type that needs none, so "no
        // constraint" is not a special case for the caller.
        ->and($types['php']['accepted_engines'])->toBe([]);
});

it('never advertises an engine the installer would refuse', function () {
    // The list comes from the installer provisioning asks, so the catalog
    // cannot offer a pairing that creating would reject.
    onlyEngines(['mysql', 'mariadb', 'mongodb']);

    foreach (siteTypeCatalog() as $name => $type) {
        if ($type['accepted_engines'] === []) {
            continue;
        }

        expect($type['needs_database'])->toBeTrue("{$name} lists engines but says it needs no database");
    }
});
