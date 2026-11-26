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
 * Otherwise each Retry Setup leaves another schema and account behind, and the
 * panel only records the newest.
 */
beforeEach(function () {
    // Every engine answers: an unreachable one would take the no-engine branch,
    // which is not what these tests are about.
    Process::fake(fn () => Process::result(output: '1'));
});

/**
 * Provision through the method that does it, rather than the helpers it calls:
 * a test calling the decision helpers directly stays green when the line that
 * *uses* them is deleted.
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

    // One database, not two.
    expect(Database::query()->count())->toBe(1)
        ->and(Database::query()->where('application_id', $application->id)->count())->toBe(1);

    // The installer gets working credentials: the password is stored encrypted, not
    // hashed, so it can be read back for the application's config file.
    expect($context['database'])->toBe('shop_xqolim')
        ->and($context['db_user'])->toBe('shop_xqolim')
        ->and($context['db_password'])->toBe('secret-from-the-first-attempt')
        ->and($context['engine'])->toBe('mysql');
});

it('still creates a database for an application that has none', function () {
    $application = Application::factory()->create(['slug' => 'blog']);

    $context = provisionFor($application);

    // The first attempt still provisions; otherwise "never provision" would pass every test above.
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

    // Not named after the database: an adopted one may already have an account by that
    // name, so the identifier comes from the generator that checks panel rows and server.
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

    // WordPress is MySQL/MariaDB only: a second database is wrong, and an engine
    // with no driver would fail later in the application's own words.
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

        // The resource renders this through `application.failure_reason.<code>`.
        expect(__('application.failure_reason.'.$e->reason))
            ->not->toBe('application.failure_reason.'.$e->reason);
    }
});
