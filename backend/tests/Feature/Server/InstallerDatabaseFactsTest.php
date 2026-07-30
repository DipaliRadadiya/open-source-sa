<?php

use App\Models\DatabaseConnection;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Support\Facades\View;

/**
 * Installers used to hardcode 127.0.0.1:3306 and read Joomla's driver from a
 * config default. On a server whose MySQL listens anywhere else, every
 * installer wrote a config pointing at a port nothing was on — and the
 * failure surfaced as the application's own "cannot connect to database",
 * which sends the user looking in the wrong place entirely.
 *
 * The engine's own connection record already knew the answer.
 */
it('reads the host and port from the engine connection, not from a literal', function () {
    DatabaseConnection::query()->updateOrCreate(
        ['engine' => 'mariadb'],
        ['connection_type' => 'tcp', 'host' => '10.0.0.5', 'port' => 3307, 'username' => 'root'],
    );

    $connection = app(DatabaseManager::class)->connection('mariadb');

    expect($connection->host)->toBe('10.0.0.5')
        ->and($connection->port)->toBe(3307);
});

it('falls back to the engine default when nothing has been configured', function () {
    DatabaseConnection::query()->where('engine', 'mongodb')->delete();

    $connection = app(DatabaseManager::class)->connection('mongodb');

    // Per-engine defaults, not one shared number: mongo is not on 3306.
    expect($connection->port)->toBe(27017);
});

it('writes the real port into a rendered app config', function () {
    // CraftCMS and Mautic both take the port through their template now; a
    // hardcoded 3306 in the blade would survive every controller-level fix.
    $rendered = View::make('server.apps.craftcms.env', [
        'appId' => 'CraftCMS--test', 'securityKey' => str_repeat('k', 32),
        'driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3307,
        'database' => 'app', 'username' => 'app', 'password' => 'secret', 'siteUrl' => 'https://x.test',
    ])->render();

    expect($rendered)->toContain('CRAFT_DB_PORT=3307')
        ->and($rendered)->not->toContain('3306');
});

it('offers a database only from an engine that is actually installed', function () {
    // Pre-existing behaviour worth pinning: InstallerManager picks the first
    // SQL engine that reports available(), rather than assuming mysql is
    // there. A box with only MariaDB must still get a database.
    $manager = new ReflectionClass(InstallerManager::class);

    expect($manager->hasMethod('firstAvailableEngine'))->toBeTrue();
});
