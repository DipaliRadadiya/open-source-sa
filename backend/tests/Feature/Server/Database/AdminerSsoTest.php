<?php

use App\Enums\ApplicationStatus;
use App\Enums\CertificateStatus;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\Server\Applications\AdminerSso;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

/*
 * One-click sign-in to Adminer — the same flow as phpMyAdmin's
 * (PhpmyadminSsoTest), for MySQL, MariaDB and PostgreSQL. The guards it shares
 * with phpMyAdmin live in DatabaseClientSso; these tests pin them for Adminer
 * too, so a change to the shared class cannot quietly loosen one client.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->ranCommands = [];
    $this->ranInput = [];

    Process::fake(function ($process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        $this->ranCommands[] = $command;
        $this->ranInput[$command] = (string) $process->input;

        return Process::result(exitCode: 0);
    });

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->database = Database::factory()->create(['engine' => 'postgresql']);

    $this->adminer = secureAdminer(Application::factory()->create([
        'site_type' => 'adminer',
        'domain' => 'adminer.example.com',
        'status' => ApplicationStatus::Active,
        'isolated_at' => now(),
    ]));
});

/** Sign-in links are refused over plain HTTP, so every happy path needs a certificate. */
function secureAdminer(Application $application): Application
{
    Certificate::factory()->create([
        'application_id' => $application->id,
        'status' => CertificateStatus::Active,
        'domains' => [$application->domain],
        'certificate_path' => "/etc/letsencrypt/live/{$application->domain}/fullchain.pem",
        'private_key_path' => "/etc/letsencrypt/live/{$application->domain}/privkey.pem",
    ]);

    return $application->refresh();
}

function asAdminerUser(string $command): string
{
    return 'runuser -u '.test()->adminer->systemUser->username.' -- '.$command;
}

/**
 * The JSON written into the token file a redirect URL names.
 *
 * @return array<string, mixed>
 */
function adminerTokenPayload(string $redirectUrl): array
{
    preg_match('/token=([A-Za-z0-9]{64})/', $redirectUrl, $matches);
    $file = test()->adminer->panelPath()."/sso/{$matches[1]}.json";

    return json_decode(test()->ranInput[asAdminerUser("tee {$file}")] ?? 'null', true) ?? [];
}

function canManageDatabases(User $user): void
{
    grantPermission($user, 'database', manage: true);
}

describe('POST /databases/{database}/adminer-sso', function () {

    it('signs into a PostgreSQL database over https', function () {
        canManageDatabases($this->user);
        $dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id, 'password' => 'pg-secret']);

        $url = $this->postJson("/api/databases/{$this->database->id}/adminer-sso")
            ->assertOk()
            ->json('redirect_url');

        $payload = adminerTokenPayload($url);

        // `pgsql` is the key index.php maps to the PostgreSQL server on this
        // machine; the browser never names a host itself.
        expect($url)->toStartWith('https://adminer.example.com/sso.php?token=')
            ->and($payload['server'])->toBe('pgsql')
            ->and($payload['username'])->toBe($dbUser->username)
            ->and($payload['password'])->toBe('pg-secret')
            ->and($payload['database'])->toBe($this->database->name)
            ->and($payload['expires_at'])->toBeLessThanOrEqual(now()->addSeconds(AdminerSso::TOKEN_TTL_SECONDS)->getTimestamp());
    });

    it('opens MySQL and MariaDB under the MySQL server', function (string $engine) {
        canManageDatabases($this->user);
        $database = Database::factory()->create(['engine' => $engine]);
        DatabaseUser::factory()->create(['database_id' => $database->id]);

        $url = $this->postJson("/api/databases/{$database->id}/adminer-sso")->assertOk()->json('redirect_url');

        expect(adminerTokenPayload($url)['server'])->toBe('mysql');
    })->with(['mysql', 'mariadb']);

    it('refuses MongoDB, naming it', function () {
        canManageDatabases($this->user);
        $mongo = Database::factory()->create(['engine' => 'mongodb']);
        DatabaseUser::factory()->create(['database_id' => $mongo->id]);

        $this->postJson("/api/databases/{$mongo->id}/adminer-sso")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Adminer cannot open MongoDB databases. It opens MySQL, MariaDB and PostgreSQL.']);

        expect($this->ranCommands)->toBe([]);
    });

    it('refuses an Adminer site without HTTPS, before writing anything', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $this->adminer->certificate()->delete();

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_requires_https')]);

        expect($this->ranCommands)->toBe([]);
    });

    it('refuses a site sharing the server-wide PHP pool, before writing anything', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $this->adminer->forceFill(['isolated_at' => null])->save();

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_not_isolated')]);

        expect($this->ranCommands)->toBe([]);
    });

    /*
     * A user adopted from another panel: the engine holds a hash and the panel
     * never learned the password. Signing in with an empty one would land the
     * user on a login form that refuses it, with no idea why.
     */
    it('says so when the panel does not know the user\'s password', function () {
        canManageDatabases($this->user);
        $dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $dbUser->forceFill(['password' => null])->save();

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_password_unknown', ['username' => $dbUser->username])]);

        expect($this->ranCommands)->toBe([]);
    });

    it('writes the wrapper and the sign-in script as the site user', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")->assertOk();

        $root = $this->adminer->rootPath().'/public_html';

        // As root, `tee` would follow a link the site user planted at either
        // path (the `.panel` class of bug, 2026-09-29).
        expect($this->ranCommands)->toContain(asAdminerUser("tee {$root}/index.php"))
            ->and($this->ranCommands)->toContain(asAdminerUser("tee {$root}/sso.php"))
            ->and(collect($this->ranCommands)->contains(fn (string $c) => str_starts_with($c, 'tee ')))->toBeFalse();
    });

    it('drops the token in a private file above the document root, never on a command line', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id, 'password' => 'sup3r-s3cret']);

        $url = $this->postJson("/api/databases/{$this->database->id}/adminer-sso")->assertOk()->json('redirect_url');

        preg_match('/token=([A-Za-z0-9]{64})/', $url, $matches);
        $file = $this->adminer->panelPath()."/sso/{$matches[1]}.json";

        expect($file)->not->toContain('/public_html/')
            ->and($this->ranCommands)->toContain(asAdminerUser("chmod 0700 {$this->adminer->panelPath()}/sso"))
            ->and($this->ranCommands)->toContain(asAdminerUser("chmod 0600 {$file}"));

        foreach ($this->ranCommands as $command) {
            expect($command)->not->toContain('sup3r-s3cret');
        }
    });

    it('records who opened which database as which account', function () {
        canManageDatabases($this->user);
        $dbUser = DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")->assertOk();

        $log = ActivityLog::latest('id')->first();

        expect($log->user_id)->toBe($this->user->id)
            ->and($log->action)->toBe('adminer_signed_in')
            ->and($log->properties['username'])->toBe($dbUser->username);
    });

    it('returns 422 when no Adminer is installed', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $this->adminer->update(['status' => ApplicationStatus::Failed]);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_not_deployed')]);
    });

    /*
     * A phpMyAdmin site is a different application with a different session
     * layout; a token written into it would never be redeemed.
     */
    it('refuses a site that is not an active Adminer, phpMyAdmin included', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $pma = secureAdminer(Application::factory()->create([
            'site_type' => 'phpmyadmin',
            'domain' => 'pma.example.com',
            'status' => ApplicationStatus::Active,
            'isolated_at' => now(),
        ]));

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso?application_id={$pma->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_not_selectable')]);
    });

    it('opens the Adminer the caller asked for, and the same default every time', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $second = secureAdminer(Application::factory()->create([
            'site_type' => 'adminer',
            'domain' => 'adminer-two.example.com',
            'status' => ApplicationStatus::Active,
            'isolated_at' => now(),
        ]));

        $asked = $this->postJson("/api/databases/{$this->database->id}/adminer-sso?application_id={$second->id}")->assertOk();
        $default = $this->postJson("/api/databases/{$this->database->id}/adminer-sso")->assertOk();

        expect($asked->json('redirect_url'))->toStartWith('https://adminer-two.example.com/')
            ->and($default->json('redirect_url'))->toStartWith('https://adminer.example.com/');
    });

    it('returns 422 when the database has no users', function () {
        canManageDatabases($this->user);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_no_users')]);
    });

    it('refuses a database user that belongs to another database', function () {
        canManageDatabases($this->user);
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);
        $other = DatabaseUser::factory()->create(['database_id' => Database::factory()->create(['engine' => 'postgresql'])->id]);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso?database_user_id={$other->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/database.adminer_user_not_found')]);
    });

    it('returns 403 without the database permission', function () {
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")->assertForbidden();

        expect($this->ranCommands)->toBe([]);
    });

    it('refuses a view-only role, because the session it opens can write', function () {
        grantPermission($this->user, 'database');
        DatabaseUser::factory()->create(['database_id' => $this->database->id]);

        $this->postJson("/api/databases/{$this->database->id}/adminer-sso")->assertForbidden();

        expect($this->ranCommands)->toBe([]);
    });
});

/*
 * PHP the panel generates and never runs itself: a syntax error would only
 * surface on a customer's server, as a blank page. TOKEN_PARSE is as close to
 * `php -l` as this gets in-process.
 */
describe('the generated files', function () {

    it('writes a sign-in script that is valid PHP with the token directory baked in', function () {
        $code = app(AdminerSso::class)->renderShim($this->adminer);

        expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class)
            ->and($code)->toContain(var_export($this->adminer->panelPath().'/sso', true))
            // Adminer's own session, and its layout — anything else is a
            // session Adminer never reads.
            ->and($code)->toContain("session_name('adminer_sid')")
            ->and($code)->toContain("\$_SESSION['pwds'][\$driver][\$server][\$username]");
    });

    it('writes a wrapper that is valid PHP and reaches only this server', function () {
        config(['server.databases.engines.postgresql.default_port' => 6543]);

        $code = app(AdminerSso::class)->renderIndex();

        expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class)
            ->and($code)->toContain("include __DIR__.'/adminer.php';")
            // The port follows the panel's configuration, not a literal.
            ->and($code)->toContain("'127.0.0.1:6543'")
            ->and($code)->toContain("'127.0.0.1:3306'");

        // Every host the wrapper can hand Adminer is on this machine.
        preg_match_all("/'host' => '([^']+)'/", $code, $hosts);
        expect($hosts[1])->not->toBeEmpty()
            ->each->toStartWith('127.0.0.1:');
    });

    /*
     * The wrapper is the whole lock-down, so run it: Adminer's login form posts
     * a server and a driver, and the driver must come from the panel's list
     * whatever the post says — otherwise `sqlite` plus a file path opens any
     * SQLite file the site user can read.
     */
    it('takes the driver from its own list, never from the login form', function () {
        $code = app(AdminerSso::class)->renderIndex();
        $dir = sys_get_temp_dir().'/adminer-wrapper-'.getmypid();
        @mkdir($dir);
        file_put_contents("{$dir}/index.php", $code);
        // A stand-in for upstream's file: prints what the wrapper left in $_POST.
        file_put_contents("{$dir}/adminer.php", '<?php echo json_encode($_POST["auth"] ?? null);');

        $run = function (array $auth) use ($dir): ?array {
            $script = '$_POST = ["auth" => '.var_export($auth, true).']; include '.var_export("{$dir}/index.php", true).';';
            exec('php -r '.escapeshellarg($script), $output);

            return json_decode(implode('', $output), true);
        };

        expect($run(['server' => 'pgsql', 'driver' => 'sqlite', 'username' => 'u'])['driver'])->toBe('pgsql')
            ->and($run(['server' => 'mysql', 'driver' => 'pgsql', 'username' => 'u'])['driver'])->toBe('server')
            ->and($run(['server' => '/etc/passwd', 'driver' => 'sqlite', 'username' => 'u'])['driver'])->toBe('');

        @unlink("{$dir}/index.php");
        @unlink("{$dir}/adminer.php");
        @rmdir($dir);
    });
});
