<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * A database could only be linked to an application in the request that
 * created it: `StoreDatabaseRequest` accepts `application_id` and nothing else
 * ever wrote the column again. So a database created on its own — or adopted
 * from a brownfield server, which cannot set it at all — was excluded from that
 * site's backups permanently, with no way to correct it.
 *
 * The link is not cosmetic. `Backups\Steps\DumpDatabase` dumps exactly the
 * attached databases; staging, cloning and restoring each find "the
 * application's database" through this column.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);

    $this->site = Application::factory()->create(['name' => 'Shop', 'site_type' => 'php']);
    $this->db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
});

it('attaches an unattached database to an application', function () {
    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => $this->site->id,
    ])->assertOk()->assertJsonPath('database.application_id', $this->site->id);

    expect($this->db->fresh()->application_id)->toBe($this->site->id);
});

it('detaches a database when application_id is null', function () {
    $this->db->update(['application_id' => $this->site->id]);

    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => null,
    ])->assertOk()->assertJsonPath('database.application_id', null);

    expect($this->db->fresh()->application_id)->toBeNull();
});

it('moves a database from one application to another', function () {
    $other = Application::factory()->create(['site_type' => 'php']);
    $this->db->update(['application_id' => $this->site->id]);

    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => $other->id,
    ])->assertOk();

    expect($this->db->fresh()->application_id)->toBe($other->id);
});

/*
 * An absent key must not be read as "detach". `present` is what makes the
 * difference between a client forgetting a field and a client asking for
 * something.
 */
it('rejects a request that omits application_id rather than detaching', function () {
    $this->db->update(['application_id' => $this->site->id]);

    $this->putJson("/api/databases/{$this->db->id}/application", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('application_id');

    expect($this->db->fresh()->application_id)->toBe($this->site->id);
});

it('refuses a second database on the same application', function () {
    Database::create(['name' => 'shop_main', 'engine' => 'mysql', 'application_id' => $this->site->id]);

    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => $this->site->id,
    ])->assertStatus(422)->assertJsonValidationErrors('application_id');

    expect($this->db->fresh()->application_id)->toBeNull();
});

/*
 * Re-attaching a database to the application it is already on is a no-op, not
 * a collision with itself — the frontend can send the current state.
 */
it('does not treat the database being edited as the application already having one', function () {
    $this->db->update(['application_id' => $this->site->id]);

    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => $this->site->id,
    ])->assertOk();
});

it('refuses an engine the application cannot speak', function () {
    $wordpress = Application::factory()->create(['name' => 'Blog', 'site_type' => 'wordpress']);
    $mongo = Database::create(['name' => 'blog', 'engine' => 'mongodb']);

    $this->putJson("/api/databases/{$mongo->id}/application", [
        'application_id' => $wordpress->id,
    ])->assertStatus(422)->assertJsonValidationErrors('application_id');

    expect($mongo->fresh()->application_id)->toBeNull();
});

it('refuses MySQL for NodeBB, which speaks MongoDB only', function () {
    $nodebb = Application::factory()->node()->create(['site_type' => 'nodebb']);

    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => $nodebb->id,
    ])->assertStatus(422)->assertJsonValidationErrors('application_id');
});

/*
 * A type that needs no database of its own — custom PHP, static, a git deploy —
 * declares no engines. The panel does not know what the user's own code
 * connects to, so it does not get an opinion.
 */
it('allows any engine on a type that declares none', function () {
    $mongo = Database::create(['name' => 'anything', 'engine' => 'mongodb']);

    $this->putJson("/api/databases/{$mongo->id}/application", [
        'application_id' => $this->site->id,
    ])->assertOk();
});

it('refuses an application that does not exist', function () {
    $this->putJson("/api/databases/{$this->db->id}/application", [
        'application_id' => 99999,
    ])->assertStatus(422)->assertJsonValidationErrors('application_id');
});

it('requires manage on database, not view', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'database', view: true, manage: false);

    $this->actingAs($viewer)
        ->putJson("/api/databases/{$this->db->id}/application", ['application_id' => $this->site->id])
        ->assertForbidden();

    expect($this->db->fresh()->application_id)->toBeNull();
});

it('records the attach and the detach in the activity log', function () {
    $this->putJson("/api/databases/{$this->db->id}/application", ['application_id' => $this->site->id])->assertOk();
    $this->putJson("/api/databases/{$this->db->id}/application", ['application_id' => null])->assertOk();

    $this->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'attached']);
    $this->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'detached']);
});

/*
 * The point of the whole feature: what an application's own screen asks.
 */
it('lists the databases attached to one application', function () {
    $this->db->update(['application_id' => $this->site->id]);
    Database::create(['name' => 'elsewhere', 'engine' => 'mysql']);

    $this->getJson("/api/databases?filter[application_id]={$this->site->id}")
        ->assertOk()
        ->assertJsonCount(1, 'databases')
        ->assertJsonPath('databases.0.name', 'shop');
});

it('lists only unattached databases for the attach picker', function () {
    Database::create(['name' => 'taken', 'engine' => 'mysql', 'application_id' => $this->site->id]);

    // `attached=0` arrives as the string "0", which is truthy in PHP — the
    // filter is worthless if it is read with a truthiness check.
    $this->getJson('/api/databases?filter[attached]=0')
        ->assertOk()
        ->assertJsonCount(1, 'databases')
        ->assertJsonPath('databases.0.name', 'shop');

    $this->getJson('/api/databases?filter[attached]=1')
        ->assertOk()
        ->assertJsonCount(1, 'databases')
        ->assertJsonPath('databases.0.name', 'taken');
});
