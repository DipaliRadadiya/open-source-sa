<?php

use App\Services\Server\Databases\DatabaseManager;

/**
 * The engine catalog has to answer for every engine, not for MySQL plus exceptions: a driver
 * with a missing key would silently inherit MySQL's answers, including the system-schema guard
 * that decides what the panel refuses to drop. A new engine with a missing key fails here.
 */
it('gives every engine a driver that is described in full', function () {
    $manager = app(DatabaseManager::class);

    foreach ($manager->engineNames() as $engine) {
        $driver = $manager->driver($engine);
        $block = config("server.databases.drivers.{$driver}");

        // PHPUnit assertions, not expectations: the failure message names the engine and key.
        $this->assertIsArray($block, "engine [{$engine}] has driver [{$driver}] with no drivers block");

        foreach (['system_schemas', 'charsets', 'rename_keeps_password', 'supports_remote_users'] as $key) {
            $this->assertArrayHasKey($key, $block, "driver [{$driver}] is missing [{$key}]");
        }
    }
});

it('gives every engine the per-engine values its callers read without a fallback', function () {
    // `dump_extension` names the export file, `uri_scheme` builds the connection string shown
    // to the user; neither may default to MySQL's value.
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

    // A driver with no list of its own would inherit MySQL's and leave its own databases droppable.
    expect($manager->isSystemDatabase('mysql', 'information_schema'))->toBeTrue()
        ->and($manager->isSystemDatabase('mongodb', 'admin'))->toBeTrue()
        ->and($manager->isSystemDatabase('mongodb', 'information_schema'))->toBeFalse()
        ->and($manager->isSystemDatabase('mysql', 'admin'))->toBeFalse();
});

it('refuses a reserved name from any engine at validation time', function () {
    // Validation refuses the union of all engines' names: refusing `admin` on MySQL is a cost
    // worth paying to never accept a name another driver would drop.
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
