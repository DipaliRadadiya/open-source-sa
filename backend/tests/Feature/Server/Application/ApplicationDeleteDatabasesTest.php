<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Deleting a site can take its databases with it — `remove_databases`, the
 * sibling of `remove_files`.
 *
 * The order is the point: the site goes first and the databases after, never
 * the reverse. A database dropped before a site delete that then failed is the
 * data of a site still serving traffic.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    config([
        'server.web_server_drivers.nginx.sites_dir' => '/etc/nginx/sites-enabled',
        'server.web_server_drivers.nginx.sites_available_dir' => '/etc/nginx/sites-available',
        // The engine writes a 0600 client auth file next to each query.
        'server.databases.auth_file_dir' => sys_get_temp_dir(),
    ]);
});

/** `forceCreate` because `slug` is not fillable — the create action assigns it. */
function siteForDeletion(array $overrides = []): Application
{
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ], $overrides));
}

function databaseAttachedTo(Application $application, string $name = 'shop_live'): Database
{
    $database = Database::create([
        'name' => $name,
        'engine' => 'mysql',
        'application_id' => $application->id,
    ]);

    $database->users()->create([
        'username' => $name.'_user',
        'password' => 'p',
        'connection_preference' => 'localhost',
        'host' => 'localhost',
    ]);

    return $database;
}

function siteDeleteHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('leaves the databases alone when the flag is absent', function () {
    Process::fake();
    $app = siteForDeletion();
    $database = databaseAttachedTo($app);

    test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk()
        ->assertExactJson(['deleted' => true]);

    // Still there, and detached rather than orphaned: `application_id` is
    // `nullOnDelete`, so the row stays reachable from the Databases screen.
    expect(Application::find($app->id))->toBeNull()
        ->and(Database::find($database->id))->not->toBeNull()
        ->and(Database::find($database->id)->application_id)->toBeNull();

    Process::assertNotRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'DROP DATABASE'));
});

it('leaves the databases alone when the flag is false', function () {
    Process::fake();
    $app = siteForDeletion();
    $database = databaseAttachedTo($app);

    test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_databases=false")
        ->assertOk()
        ->assertExactJson(['deleted' => true]);

    expect(Database::find($database->id))->not->toBeNull();
});

it('deletes the site and then its databases when asked', function () {
    $order = [];
    Process::fake(function ($process) use (&$order) {
        $sql = (string) ($process->input ?? '');
        $command = implode(' ', (array) $process->command);

        if (str_contains($command, 'sites-enabled')) {
            $order[] = 'site';
        }
        if (str_contains($sql, 'DROP DATABASE')) {
            $order[] = 'database';
        }

        return Process::result(output: '1');
    });

    $app = siteForDeletion();
    $database = databaseAttachedTo($app);
    $dbUser = $database->users->first();

    test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_databases=true")
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('databases.deleted', ['shop_live'])
        ->assertJsonPath('databases.failed', [])
        // No warning on a clean delete — it would read as a warning about
        // nothing.
        ->assertJsonMissingPath('message');

    expect(Application::find($app->id))->toBeNull()
        ->and(Database::find($database->id))->toBeNull()
        // Cascade, so the account goes with its database.
        ->and(DatabaseUser::find($dbUser->id))->toBeNull();

    // Site first, database after. Reversed, a failed site delete would leave a
    // live site whose data had already been dropped.
    expect(array_search('site', $order, true))->toBeLessThan(array_search('database', $order, true));
});

it('takes every database even when one of them fails', function () {
    // Independent, not one `foreach` inside one `try`: the first failure used
    // to end the loop in `removeBackups`, and one unreachable target orphaned
    // everything behind it.
    Process::fake(function ($process) {
        $sql = (string) ($process->input ?? '');

        return str_contains($sql, 'DROP DATABASE IF EXISTS `shop_logs`')
            ? Process::result(exitCode: 1, errorOutput: 'ERROR 1045 (28000): Access denied')
            : Process::result(output: '1');
    });

    $app = siteForDeletion();
    // Two attached databases are refused on the attach endpoint, but brownfield
    // adoption and rows written before that cap exists can still produce them.
    $logs = databaseAttachedTo($app, 'shop_logs');
    $live = databaseAttachedTo($app, 'shop_live');

    $response = test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_databases=true")
        // 200, not 500: the site really is gone, and an error status would
        // tell the panel nothing happened when most of it did.
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('databases.deleted', ['shop_live'])
        ->assertJsonPath('databases.failed.0.name', 'shop_logs')
        ->assertJsonPath('databases.failed.0.engine', 'mysql');

    // Named in the message, so the panel can say what is left behind without
    // the backend hardcoding English.
    expect($response->json('message'))->toContain('shop_logs')
        ->and($response->json('databases.failed.0.reference'))->not->toBeEmpty();

    expect(Application::find($app->id))->toBeNull()
        ->and(Database::find($live->id))->toBeNull()
        // Kept, deliberately: `DeleteDatabase` leaves the row so the same
        // delete can be retried from the Databases screen until it finishes.
        ->and(Database::find($logs->id))->not->toBeNull();
});

it('never touches another site\'s database', function () {
    Process::fake(fn () => Process::result(output: '1'));

    $app = siteForDeletion();
    $other = siteForDeletion(['slug' => 'other', 'domain' => 'other.example.com', 'name' => 'Other']);
    $mine = databaseAttachedTo($app);
    $theirs = databaseAttachedTo($other, 'other_live');

    test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_databases=true")
        ->assertOk()
        ->assertJsonPath('databases.deleted', ['shop_live']);

    expect(Database::find($mine->id))->toBeNull()
        ->and(Database::find($theirs->id))->not->toBeNull();

    Process::assertNotRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'other_live'));
});

it('refuses the flag for someone who can delete sites but not databases', function () {
    Process::fake();
    $user = User::factory()->create();
    grantPermission($user, 'application', view: true, manage: true);
    grantPermission($user, 'database', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $app = siteForDeletion();
    $database = databaseAttachedTo($app);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/applications/{$app->id}?remove_databases=true")
        ->assertForbidden();

    // The refusal arrives before anything is removed — a 403 here means the
    // site is still there, not that it went and the database stayed.
    expect(Application::find($app->id))->not->toBeNull()
        ->and(Database::find($database->id))->not->toBeNull()
        // Not the default "This action is unauthorized", which would describe
        // deleting the site — the thing this caller is allowed to do.
        ->and($response->json('message'))->toContain('databases');

    Process::assertNothingRan();
});

it('still lets that person delete the site without its databases', function () {
    Process::fake();
    $user = User::factory()->create();
    grantPermission($user, 'application', view: true, manage: true);
    $token = $user->createToken('t')->plainTextToken;

    $app = siteForDeletion();
    $database = databaseAttachedTo($app);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk();

    expect(Application::find($app->id))->toBeNull()
        ->and(Database::find($database->id))->not->toBeNull();
});

it('rejects a flag that is not a boolean', function () {
    Process::fake();
    $app = siteForDeletion();

    // `remove_files` had no validation at all until this endpoint took a
    // FormRequest: `boolean()` reads anything it does not recognise as false,
    // so a typo silently meant "keep" and nothing said so.
    test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_databases=maybe")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('remove_databases');

    test()->withHeaders(siteDeleteHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_files=maybe")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('remove_files');

    expect(Application::find($app->id))->not->toBeNull();
    Process::assertNothingRan();
});
