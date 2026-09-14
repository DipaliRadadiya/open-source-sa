<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\Server\Applications\InstallerManager;
use Illuminate\Support\Facades\Process;

/**
 * Retrying a failed setup must not create a second database.
 *
 * `provisionDatabase()` generated a fresh name and called CreateDatabase every
 * time it ran, without ever asking whether the application already had one. So
 * each press of Retry Setup left another schema and another account behind —
 * on a server where nothing but the panel knows which of them the site uses,
 * and the panel only records the newest.
 */
beforeEach(function () {
    // Every engine answers. The engine is not what these tests are about, and
    // an unreachable one would send the create path down the no-engine branch
    // for reasons unrelated to the behaviour under test.
    Process::fake(fn () => Process::result(output: '1'));
});

/**
 * Provision through the method that does it, rather than the helpers it calls.
 *
 * Same reasoning as CatalogEngineTest: a test that calls the decision helpers
 * directly stays green when the line that *uses* them is deleted.
 *
 * @param  array<int, string>  $accepted
 * @return array<string, mixed>
 */
function provisionFor(Application $application, array $accepted = ['mysql']): array
{
    $manager = app(InstallerManager::class);
    $method = new ReflectionMethod($manager, 'provisionDatabase');

    return $method->invoke($manager, $application, $accepted);
}

it('reuses the database already attached to the application', function () {
    $application = Application::factory()->create(['slug' => 'shop']);

    $database = Database::create([
        'name' => 'shop_xqolim',
        'engine' => 'mysql',
        'application_id' => $application->id,
    ]);

    $database->users()->create([
        'username' => 'shop_xqolim',
        'password' => 'secret-from-the-first-attempt',
        'connection_preference' => 'localhost',
        'host' => 'localhost',
    ]);

    $context = provisionFor($application);

    // The bug, stated as an assertion: one database, not two.
    expect(Database::query()->count())->toBe(1)
        ->and(Database::query()->where('application_id', $application->id)->count())->toBe(1);

    // And the installer is handed credentials that actually work — the
    // password is stored encrypted rather than hashed precisely so it can be
    // read back for the application's own config file.
    expect($context['database'])->toBe('shop_xqolim')
        ->and($context['db_user'])->toBe('shop_xqolim')
        ->and($context['db_password'])->toBe('secret-from-the-first-attempt')
        ->and($context['engine'])->toBe('mysql');
});

it('still creates a database for an application that has none', function () {
    $application = Application::factory()->create(['slug' => 'blog']);

    $context = provisionFor($application);

    // The first attempt is unchanged. Without this the fix could be "never
    // provision a database" and every test above would still pass.
    expect(Database::query()->where('application_id', $application->id)->count())->toBe(1)
        ->and($context['database'])->toStartWith('blog_')
        ->and($context['db_user'])->toBe($context['database'])
        ->and($context['db_password'])->not->toBe('');
});

it('gives an adopted database a user rather than a second database', function () {
    $application = Application::factory()->create(['slug' => 'legacy']);

    // What `PUT /databases/{database}/application` produces: a real database
    // linked to the app, with no credentials the panel knows.
    Database::create([
        'name' => 'legacy_data',
        'engine' => 'mysql',
        'application_id' => $application->id,
    ]);

    $context = provisionFor($application);

    expect(Database::query()->count())->toBe(1)
        ->and($context['database'])->toBe('legacy_data')
        ->and(DatabaseUser::query()->count())->toBe(1);

    // Not named after the database: an adopted one may already have an account
    // by that name on the engine, which is why the identifier is allocated
    // through the generator that checks both the panel's rows and the server.
    expect($context['db_user'])->toStartWith('legacy_')
        ->and($context['db_password'])->not->toBe('');
});

it('refuses an attached database on an engine the application cannot use', function () {
    $application = Application::factory()->create(['slug' => 'wp']);

    $database = Database::create([
        'name' => 'wp_pgsql',
        'engine' => 'postgresql',
        'application_id' => $application->id,
    ]);

    $database->users()->create([
        'username' => 'wp_pgsql',
        'password' => 'secret',
        'connection_preference' => 'localhost',
        'host' => 'localhost',
    ]);

    // WordPress is MySQL/MariaDB only. Creating a second database beside this
    // one is the bug; installing against an engine it has no driver for fails
    // later, further away, in the application's own words.
    expect(fn () => provisionFor($application, ['mysql', 'mariadb']))
        ->toThrow(
            ProvisioningFailedException::class,
        );

    expect(Database::query()->count())->toBe(1);
});

it('names the mismatch as a reason the user can read', function () {
    $application = Application::factory()->create(['slug' => 'wp']);

    Database::create([
        'name' => 'wp_pgsql',
        'engine' => 'postgresql',
        'application_id' => $application->id,
    ]);

    try {
        provisionFor($application, ['mysql']);
        $this->fail('provisioning should have refused the mismatched engine');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('create_database')
            ->and($e->reason)->toBe('attached_database_engine_mismatch');

        // Translated, not a raw code shown to somebody: the resource renders
        // this through `application.failure_reason.<code>`.
        expect(__('application.failure_reason.'.$e->reason))
            ->not->toBe('application.failure_reason.'.$e->reason);
    }
});
