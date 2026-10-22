<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/*
 * The databases list names the application a database is attached to, next to
 * the `application_id` it always returned. The screen only had the id, so it
 * could not say which site a database belongs to without a second request.
 *
 * The name is eager-loaded by the list alone: the shared DatabaseResource adds
 * it only when `application` is loaded, so the other endpoints answer exactly
 * as before and nothing lazy-loads the relation per row.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);

    $this->shop = Application::factory()->create(['name' => 'Shop', 'site_type' => 'php']);
});

it('names the attached application and leaves an unattached database null', function () {
    Database::create(['name' => 'a_attached', 'engine' => 'mysql', 'application_id' => $this->shop->id]);
    Database::create(['name' => 'b_loose', 'engine' => 'mysql']);

    $this->getJson('/api/databases?sort=name')
        ->assertOk()
        ->assertJsonCount(2, 'databases')
        ->assertJsonPath('databases.0.name', 'a_attached')
        ->assertJsonPath('databases.0.application_id', $this->shop->id)
        ->assertJsonPath('databases.0.application_name', 'Shop')
        ->assertJsonPath('databases.1.name', 'b_loose')
        ->assertJsonPath('databases.1.application_id', null)
        ->assertJsonPath('databases.1.application_name', null);
});

it('keeps every existing field and the pagination meta, adding only application_name', function () {
    Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);

    $response = $this->getJson('/api/databases')->assertOk();

    expect(array_keys($response->json('databases.0')))->toBe([
        'id', 'name', 'engine', 'driver', 'charset', 'collation', 'application_id', 'application_name',
        'size_bytes', 'size_human', 'users_count', 'created_at', 'created_at_human',
    ])
        ->and(array_keys($response->json()))->toBe(['databases', 'meta'])
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1]);
});

it('shows the current name of the application, not a stale one', function () {
    Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);
    $this->shop->update(['name' => 'Storefront']);

    $this->getJson('/api/databases')
        ->assertOk()
        ->assertJsonPath('databases.0.application_name', 'Storefront');
});

it('names the application through every filter, the search and the paging', function () {
    $blog = Application::factory()->create(['name' => 'Blog', 'site_type' => 'php']);
    Database::create(['name' => 'shop_db', 'engine' => 'mysql', 'application_id' => $this->shop->id]);
    Database::create(['name' => 'blog_db', 'engine' => 'postgresql', 'application_id' => $blog->id]);
    Database::create(['name' => 'loose_db', 'engine' => 'mysql']);

    $this->getJson("/api/databases?filter[application_id]={$blog->id}")
        ->assertOk()
        ->assertJsonCount(1, 'databases')
        ->assertJsonPath('databases.0.application_name', 'Blog');

    $this->getJson('/api/databases?filter[attached]=1&sort=name')
        ->assertOk()
        ->assertJsonCount(2, 'databases')
        ->assertJsonPath('databases.0.application_name', 'Blog')
        ->assertJsonPath('databases.1.application_name', 'Shop');

    $this->getJson('/api/databases?filter[attached]=0')
        ->assertOk()
        ->assertJsonCount(1, 'databases')
        ->assertJsonPath('databases.0.name', 'loose_db')
        ->assertJsonPath('databases.0.application_name', null);

    $this->getJson('/api/databases?filter[engine]=mysql&sort=name')
        ->assertOk()
        ->assertJsonCount(2, 'databases')
        ->assertJsonPath('databases.0.application_name', null)
        ->assertJsonPath('databases.1.application_name', 'Shop');

    $this->getJson('/api/databases?search=shop')
        ->assertOk()
        ->assertJsonCount(1, 'databases')
        ->assertJsonPath('databases.0.application_name', 'Shop');

    foreach (range(1, 9) as $i) {
        Database::create(['name' => "z_{$i}", 'engine' => 'mysql', 'application_id' => $this->shop->id]);
    }

    $this->getJson('/api/databases?sort=name&page=2')
        ->assertOk()
        ->assertJsonCount(2, 'databases')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('databases.0.name', 'z_8')
        ->assertJsonPath('databases.0.application_name', 'Shop');
});

it('does not ask the database once per row for the application', function () {
    $count = function (): int {
        DB::flushQueryLog();
        test()->getJson('/api/databases')->assertOk();

        return count(DB::getQueryLog());
    };

    Database::create(['name' => 'db_0', 'engine' => 'mysql', 'application_id' => $this->shop->id]);

    DB::enableQueryLog();

    // Warm-up: the first request pays for things a later one does not.
    $count();

    $one = $count();

    // Each on its own application, so a per-row lookup could not hide
    // behind one shared parent.
    foreach (range(1, 4) as $i) {
        $app = Application::factory()->create(['site_type' => 'php']);
        Database::create(['name' => "db_{$i}", 'engine' => 'mysql', 'application_id' => $app->id]);
    }

    $five = $count();
    DB::disableQueryLog();

    expect($five)->toBe($one);
});

it('never lazy-loads the application while building the list', function () {
    $blog = Application::factory()->create(['name' => 'Blog', 'site_type' => 'php']);
    Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);
    Database::create(['name' => 'blog', 'engine' => 'mysql', 'application_id' => $blog->id]);
    Database::create(['name' => 'loose', 'engine' => 'mysql']);

    Model::preventLazyLoading();

    try {
        $this->getJson('/api/databases')->assertOk()->assertJsonCount(3, 'databases');
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('does not add application_name to the other database responses', function () {
    Process::fake();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);

    $this->getJson("/api/databases/{$db->id}")
        ->assertOk()
        ->assertJsonPath('database.application_id', $this->shop->id)
        ->assertJsonMissingPath('database.application_name');

    $this->putJson("/api/databases/{$db->id}/application", ['application_id' => null])
        ->assertOk()
        ->assertJsonPath('database.application_id', null)
        ->assertJsonMissingPath('database.application_name');
});

it('refuses an anonymous caller', function () {
    Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/databases')
        ->assertUnauthorized()
        ->assertJsonMissingPath('databases');
});

it('refuses a user who may not view databases', function () {
    Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/databases')
        ->assertForbidden()
        ->assertJsonMissingPath('databases');
});

it('lets a user with view permission on databases see the application name', function () {
    Database::create(['name' => 'shop', 'engine' => 'mysql', 'application_id' => $this->shop->id]);
    $viewer = User::factory()->create();
    grantPermission($viewer, 'database');

    $this->actingAs($viewer)
        ->getJson('/api/databases')
        ->assertOk()
        ->assertJsonPath('databases.0.application_name', 'Shop');
});
