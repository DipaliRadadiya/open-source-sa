<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // A non-MongoDB database for happy-path tests.
    $this->database = Database::factory()->create(['engine' => 'mariadb']);

    // A running phpMyAdmin application on the server.
    $this->pmaApp = Application::factory()->create([
        'site_type' => 'phpmyadmin',
        'domain' => 'pma.example.com',
        'status' => ApplicationStatus::Running,
    ]);
});

function grantDatabasePermission(User $user): void
{
    $role = \App\Models\Role::factory()->create(['is_system' => false]);
    $role->permissions()->sync(['database']);
    $user->roles()->sync([$role->id]);
}

describe('POST /databases/{database}/phpmyadmin-sso', function () {

    it('returns redirect_url for a mysql database with a running PMA app', function () {
        grantDatabasePermission($this->user);

        $user = DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);
        $this->assertStringStartsWith("https://{$this->pmaApp->domain}/sso.php?token=", $response->json('redirect_url'));
    });

    it('returns redirect_url when a specific database_user_id is provided', function () {
        grantDatabasePermission($this->user);

        $dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso?database_user_id={$dbUser->id}");

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);
    });

    it('stores a valid token in the cache', function () {
        grantDatabasePermission($this->user);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        $url = $response->json('redirect_url');
        preg_match('/token=([a-zA-Z0-9]+)/', $url, $matches);
        $token = $matches[1];

        $cached = Cache::get("pma_sso:{$token}");
        expect($cached)->not()->toBeNull()
            ->and($cached['user_id'])->toBe($this->user->id)
            ->and($cached['database_id'])->toBe($this->database->id);
    });

    it('returns 422 for a MongoDB database', function () {
        grantDatabasePermission($this->user);

        $mongoDb = Database::factory()->create(['engine' => 'mongodb']);

        $response = $this->postJson("/api/databases/{$mongoDb->id}/phpmyadmin-sso");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'phpMyAdmin does not support MongoDB databases.']);
    });

    it('returns 422 when no phpMyAdmin site is deployed', function () {
        grantDatabasePermission($this->user);

        // Create a stopped/removed PMA app instead of a running one.
        Application::factory()->create([
            'site_type' => 'phpmyadmin',
            'status' => ApplicationStatus::Stopped,
        ]);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'No phpMyAdmin site is installed on this server.']);
    });

    it('returns 422 when the database has no users', function () {
        grantDatabasePermission($this->user);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Create a database user before accessing phpMyAdmin.']);
    });

    it('returns 422 when the specified database_user_id does not belong to this database', function () {
        grantDatabasePermission($this->user);

        $otherDb = Database::factory()->create(['engine' => 'mariadb']);
        $otherUser = DatabaseUser::factory()->create(['database_id' => $otherDb->id]);
        $thisUser = DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso?database_user_id={$otherUser->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'The specified database user does not belong to this database.']);
    });

    it('returns 403 when the user lacks the database permission', function () {
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        $response->assertForbidden();
    });

    it('returns 404 for a non-existent database', function () {
        grantDatabasePermission($this->user);

        $response = $this->postJson('/api/databases/99999/phpmyadmin-sso');

        $response->assertNotFound();
    });
});
