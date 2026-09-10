<?php

use App\Services\Server\Databases\DatabaseManager;

/**
 * The engine catalog has to answer for every engine, not for MySQL plus two
 * exceptions.
 *
 * These facts used to be written inline as `driver === 'mongo' ? x : y`, at
 * five call sites, where the `y` arm silently meant MySQL. Adding a third
 * driver would have inherited MySQL's answers everywhere without one line
 * changing — including the system-schema guard, which decides what the panel
 * refuses to drop. A guard that fails open is worse than no guard, because
 * everything above it reports success.
 *
 * So the completeness rule lives here rather than in a code review: a new
 * engine with a missing key fails the build, at the point somebody adds it.
 */
it('gives every engine a driver that is described in full', function () {
    $manager = app(DatabaseManager::class);

    foreach ($manager->engineNames() as $engine) {
        $driver = $manager->driver($engine);
        $block = config("server.databases.drivers.{$driver}");

        // PHPUnit assertions rather than expectations: these carry a message,
        // and the message *is* the feature — "driver [pgsql] is missing
        // [system_schemas]" is the whole point of the failure.
        $this->assertIsArray($block, "engine [{$engine}] has driver [{$driver}] with no drivers block");

        foreach (['system_schemas', 'charsets', 'rename_keeps_password', 'supports_remote_users'] as $key) {
            $this->assertArrayHasKey($key, $block, "driver [{$driver}] is missing [{$key}]");
        }
    }
});

it('gives every engine the per-engine values its callers read without a fallback', function () {
    // `dump_extension` names the export file and `uri_scheme` builds the
    // connection string shown to the user. Both were ternaries defaulting to
    // MySQL, so both would have been quietly wrong for a new engine rather
    // than absent.
    foreach (app(DatabaseManager::class)->engineNames() as $engine) {
        foreach (['dump_extension', 'uri_scheme', 'default_port'] as $key) {
            $this->assertNotNull(
                config("server.databases.engines.{$engine}.{$key}"),
                "engine [{$engine}] is missing [{$key}]",
            );
        }
    }
});

it('protects each engine own system databases and no one else', function () {
    $manager = app(DatabaseManager::class);

    // The specific failure this replaces: a driver with no list of its own
    // inherited MySQL's, so it protected four databases that do not exist on
    // it while its own were droppable.
    expect($manager->isSystemDatabase('mysql', 'information_schema'))->toBeTrue()
        ->and($manager->isSystemDatabase('mongodb', 'admin'))->toBeTrue()
        ->and($manager->isSystemDatabase('mongodb', 'information_schema'))->toBeFalse()
        ->and($manager->isSystemDatabase('mysql', 'admin'))->toBeFalse();
});

it('refuses a reserved name from any engine at validation time', function () {
    // Validation runs before the engine is necessarily trustworthy, so it
    // refuses the union. Refusing `admin` as a MySQL database name is a cost
    // worth paying to never accept one that another driver would drop.
    expect(app(DatabaseManager::class)->allSystemSchemas())
        ->toContain('information_schema')
        ->toContain('admin')
        ->toContain('local');
});

it('offers charsets only where the engine has the concept', function () {
    $manager = app(DatabaseManager::class);

    expect($manager->charsets('mysql'))->toHaveKey('utf8mb4')
        ->and($manager->charsets('mongodb'))->toBe([]);
});
