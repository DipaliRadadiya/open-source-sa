<?php

use App\Enums\ApplicationStatus;
use App\Enums\CertificateStatus;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Server\Applications\PhpMyAdminSso;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Without the catalog there are no permissions to grant, so every
    // authorisation check below would be testing an empty set.
    $this->seed(PermissionSeeder::class);

    // Issuing a link writes the sign-in script and the token file to the
    // phpMyAdmin site, so every path through this endpoint now runs commands.
    // Recorded through the closure form rather than Process::recorded(), which
    // does not exist on this version's fake.
    $this->ranCommands = [];

    Process::fake(function ($process) {
        $this->ranCommands[] = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return Process::result(exitCode: 0);
    });

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // A non-MongoDB database for happy-path tests.
    $this->database = Database::factory()->create(['engine' => 'mariadb']);

    // A running phpMyAdmin application on the server. `isolated_at` matters:
    // the token file is only private to this site when the site has its own
    // PHP-FPM pool, so without it the endpoint refuses.
    $this->pmaApp = Application::factory()->create([
        'site_type' => 'phpmyadmin',
        'domain' => 'pma.example.com',
        'status' => ApplicationStatus::Active,
        'isolated_at' => now(),
    ]);
});

/**
 * Every command the endpoint ran, as a flat string.
 *
 * A free function rather than a closure property because Pest shares helper
 * names across the whole suite and `ssoCommands` is specific enough not to
 * collide with one.
 *
 * @return array<int, string>
 */
function ssoCommands(): array
{
    return test()->ranCommands;
}

/**
 * Uses the suite's shared helper rather than a local one: permissions are
 * granted through a role, and the pivot carries `view`/`manage`. Syncing a
 * permission *name* as if it were an id silently attaches nothing (or, on
 * SQLite, trips the foreign key).
 */
/**
 * Permissions are role-based, and the pivot carries `view`/`manage` — a bare
 * `sync(['database'])` passes a permission *name* where an id belongs, so it
 * attaches nothing and trips the foreign key on the way past.
 */
function grantDatabasePermission(User $user): void
{
    $role = Role::factory()->create(['is_system' => false]);
    $permission = Permission::firstWhere('name', 'database');

    $role->permissions()->attach($permission->id, ['view' => true, 'manage' => true]);
    $user->roles()->sync([$role->id]);
}

describe('POST /databases/{database}/phpmyadmin-sso', function () {

    /*
     * The scheme is the bug this endpoint shipped with: it said `https://`
     * whatever the site could answer on, and a site with no certificate has no
     * TLS listener at all — so the button led to a connection refused, which
     * reads as a broken panel rather than a missing certificate.
     */
    it('sends the user to the scheme the site actually answers on', function () {
        grantDatabasePermission($this->user);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        $response->assertOk()->assertJsonStructure(['redirect_url']);
        expect($response->json('redirect_url'))
            ->toStartWith("http://{$this->pmaApp->domain}/sso.php?token=");
    });

    it('sends the user over https once the site has a servable certificate', function () {
        grantDatabasePermission($this->user);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        Certificate::factory()->create([
            'application_id' => $this->pmaApp->id,
            'status' => CertificateStatus::Active,
            'certificate_path' => '/etc/letsencrypt/live/pma.example.com/fullchain.pem',
            'private_key_path' => '/etc/letsencrypt/live/pma.example.com/privkey.pem',
        ]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        expect($response->json('redirect_url'))
            ->toStartWith("https://{$this->pmaApp->domain}/sso.php?token=");
    });

    it('returns redirect_url when a specific database_user_id is provided', function () {
        grantDatabasePermission($this->user);

        $dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso?database_user_id={$dbUser->id}");

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);
    });

    /*
     * The other half of why this never worked: the token went into the panel's
     * cache and the URL pointed at a `sso.php` that no installer had ever
     * written. Nothing on the phpMyAdmin site could read the one or serve the
     * other.
     */
    it('writes the sign-in script onto the phpMyAdmin site', function () {
        grantDatabasePermission($this->user);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso")->assertOk();

        $shim = $this->pmaApp->rootPath().'/public_html/sso.php';

        expect(ssoCommands())->toContain("tee {$shim}");
    });

    it('drops the token in a private file above the document root', function () {
        grantDatabasePermission($this->user);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $response = $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso");

        preg_match('/token=([a-zA-Z0-9]{64})/', (string) $response->json('redirect_url'), $matches);
        $file = $this->pmaApp->panelPath()."/sso/{$matches[1]}.json";

        // Above the document root, so no visitor can request it by name, and
        // 0600 so no other account on the server can read the password in it.
        expect($file)->not->toContain('/public_html/')
            ->and(ssoCommands())->toContain("tee {$file}")
            ->and(ssoCommands())->toContain("chmod 0600 {$file}");
    });

    it('never puts the credentials on a command line', function () {
        grantDatabasePermission($this->user);

        $dbUser = DatabaseUser::factory()->create([
            'database_id' => $this->database->id,
            'password' => 'sup3r-s3cret',
        ]);

        $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso")->assertOk();

        // The payload travels over stdin. On argv it would be readable in `ps`
        // by every account on the box for as long as `tee` runs.
        foreach (ssoCommands() as $command) {
            expect($command)->not->toContain('sup3r-s3cret')
                ->and($command)->not->toContain($dbUser->username.':');
        }
    });

    /*
     * Without its own PHP-FPM pool the site runs as the same account as every
     * other site on the server, and a file "only phpMyAdmin can read" is a file
     * they can all read.
     */
    it('refuses to mint a link for a site sharing the server-wide PHP pool', function () {
        grantDatabasePermission($this->user);

        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        // forceFill: `isolated_at` is written by the provisioner, not by a
        // request, so it is not mass-assignable and update() would drop it.
        $this->pmaApp->forceFill(['isolated_at' => null])->save();

        $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso")
            ->assertStatus(422);

        expect(ssoCommands())->toBeEmpty();
    });

    it('records who signed in to which database as which account', function () {
        grantDatabasePermission($this->user);

        $dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $this->postJson("/api/databases/{$this->database->id}/phpmyadmin-sso")->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'type' => 'database',
            'action' => 'phpmyadmin_signed_in',
        ]);

        expect(ActivityLog::latest('id')->first()->properties['username'])->toBe($dbUser->username);
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

        // The only phpMyAdmin site is one that never finished provisioning:
        // the record exists but nothing is being served, so SSO has nowhere
        // to send the user. There is no "stopped" status — a site is Pending,
        // Provisioning, Active or Failed.
        $this->pmaApp->update(['status' => ApplicationStatus::Failed]);

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

/*
 * Both of these files are PHP the panel generates and never executes itself,
 * so a syntax error in either would only ever surface on a customer's server —
 * as a blank page from phpMyAdmin, with the cause in a log the panel does not
 * read. TOKEN_PARSE makes the tokeniser reject what it would otherwise skip
 * past, which is as close to `php -l` as this can get in-process.
 */
describe('the generated files', function () {

    it('writes a sign-in script that is valid PHP', function () {
        $application = Application::factory()->create([
            'site_type' => 'phpmyadmin',
            'isolated_at' => now(),
        ]);

        $code = app(PhpMyAdminSso::class)->renderShim($application);

        expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class);

        // The token directory is baked in rather than derived at request time:
        // the script must not be able to be pointed somewhere else by whoever
        // is calling it.
        expect($code)->toContain($application->panelPath().'/sso');
    });

    it('writes a phpMyAdmin configuration that is valid PHP and offers both logins', function () {
        $code = app(PhpMyAdminSso::class)->renderConfig(
            str_repeat('a', 64),
            '127.0.0.1',
            '/home/site/app/tmp',
        );

        expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class);

        // Server 1 stays on cookie auth so typing your own credentials keeps
        // working; server 2 is the panel's one-click entry. Losing either is a
        // regression somebody would only notice in a browser.
        expect($code)->toContain("\$cfg['Servers'][\$i]['auth_type'] = 'cookie';")
            ->and($code)->toContain("\$cfg['Servers'][\$i]['auth_type'] = 'signon';")
            ->and($code)->toContain(PhpMyAdminSso::SIGNON_SESSION);
    });
});
