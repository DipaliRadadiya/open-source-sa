<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * A role that may only look must not be handed a working credential.
 *
 * Both lists sat behind `view`, and both returned the plaintext password —
 * a database user's, with a ready-made connection string (DB-01), and a
 * system user's SSH/SFTP login (SU-01). The password goes to `manage` only;
 * `password_known` keeps telling a read-only screen whether one is set.
 * A deploy webhook's secret is the same kind of thing and goes to whoever may
 * deploy.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->user = User::factory()->create();
});

describe('database users', function () {
    beforeEach(function () {
        $this->database = Database::factory()->create(['engine' => 'mariadb']);
        DatabaseUser::factory()->create(['database_id' => $this->database->id, 'password' => 'S3cret-db-pass']);
    });

    it('gives a view-only role neither the password nor a connection string', function () {
        grantPermission($this->user, 'database');

        foreach (["/api/databases/{$this->database->id}/users", "/api/databases/{$this->database->id}"] as $url) {
            $response = $this->actingAs($this->user)->getJson($url)->assertOk();

            expect($response->getContent())->not->toContain('S3cret-db-pass');
        }

        $this->actingAs($this->user)->getJson("/api/databases/{$this->database->id}/users")
            ->assertJsonPath('users.0.password', null)
            ->assertJsonPath('users.0.password_known', true)
            ->assertJsonPath('users.0.connection_string', null)
            // Where to connect is not a secret, and a read-only screen lost it
            // with the connection string (FS-B11).
            ->assertJsonPath('users.0.connection.port', 3306)
            ->assertJsonPath('users.0.connection.database', $this->database->name);
    });

    it('still gives them to a role that may manage databases', function () {
        grantPermission($this->user, 'database', manage: true);

        $this->actingAs($this->user)->getJson("/api/databases/{$this->database->id}/users")
            ->assertOk()
            ->assertJsonPath('users.0.password', 'S3cret-db-pass');
    });
});

describe('system users', function () {
    beforeEach(function () {
        $this->systemUser = SystemUser::create([
            'username' => 'siteowner', 'home_path' => '/home/siteowner', 'password' => 'S3cret-ssh-pass',
        ]);
    });

    it('gives a view-only role no password, in the list or the detail', function () {
        grantPermission($this->user, 'system_user');

        foreach (['/api/system-users', "/api/system-users/{$this->systemUser->id}"] as $url) {
            $response = $this->actingAs($this->user)->getJson($url)->assertOk();

            expect($response->getContent())->not->toContain('S3cret-ssh-pass');
        }

        $this->actingAs($this->user)->getJson("/api/system-users/{$this->systemUser->id}")
            ->assertJsonPath('system_user.password', null)
            ->assertJsonPath('system_user.password_known', true);
    });

    it('still gives it to a role that may manage system users', function () {
        grantPermission($this->user, 'system_user', manage: true);

        $this->actingAs($this->user)->getJson("/api/system-users/{$this->systemUser->id}")
            ->assertOk()
            ->assertJsonPath('system_user.password', 'S3cret-ssh-pass');
    });
});

describe('deploy webhook secret', function () {
    beforeEach(function () {
        // With the URL and this secret anyone can sign a push and start a
        // deployment, so it follows POST /deploy (`app_deployment` manage),
        // not the right to look at the application.
        $this->application = Application::factory()->create([
            'webhook_enabled' => true,
            'webhook_provider' => 'github',
            'webhook_identifier' => 'hook-id',
            'webhook_secret' => 'S3cret-webhook-value',
        ]);
    });

    it('is withheld from a role that may only view the application', function () {
        grantPermission($this->user, 'application', manage: true);

        $response = $this->actingAs($this->user)->getJson("/api/applications/{$this->application->id}")->assertOk();

        expect($response->getContent())->not->toContain('S3cret-webhook-value');
        $response->assertJsonPath('application.webhook.enabled', true);
    });

    it('is shown to a role that may deploy', function () {
        grantPermission($this->user, 'application');
        grantPermission($this->user, 'app_deployment', manage: true);

        $this->actingAs($this->user)->getJson("/api/applications/{$this->application->id}")
            ->assertOk()
            ->assertJsonPath('application.webhook.secret', 'S3cret-webhook-value');
    });
});
