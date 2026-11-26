<?php

use App\Actions\Server\Database\CreateDatabase;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\Database;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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

    Http::preventStrayRequests();
});

/** The salt service. Each test opts in: an install must not depend on it being up. */
function fakeSaltService(bool $reachable = true): void
{
    Http::fake(['api.wordpress.org/*' => $reachable
        ? Http::response(
            "define('AUTH_KEY', 'a1');\ndefine('SECURE_AUTH_KEY', 'a2');\ndefine('LOGGED_IN_KEY', 'a3');\n"
            ."define('NONCE_KEY', 'a4');\ndefine('AUTH_SALT', 'a5');\ndefine('SECURE_AUTH_SALT', 'a6');\n"
            ."define('LOGGED_IN_SALT', 'a7');\ndefine('NONCE_SALT', 'a8');\n"
        )
        : Http::response('', 500),
    ]);
}

function wpApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Blog '.Str::random(6),
        'domain' => 'blog.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'site_title' => 'My Blog',
            'admin_user' => 'admin',
            'admin_email' => 'me@example.com',
            'admin_password' => 'Sup3rSecretPassw0rd',
            'table_prefix' => 'wp_',
        ],
    ], $overrides));
}

/**
 * A wordpress application with a slug, the way a real one is created.
 *
 * `slug` is deliberately not fillable — {@see CreateApplication} sets it with `forceCreate`
 * because it names the web-server config file. Passing it to `create()` yields a silent null.
 */
function sluggedApp(string $slug, array $overrides = []): Application
{
    $application = wpApp($overrides);
    $application->forceFill(['slug' => $slug])->save();

    return $application;
}

/** MySQL present and reachable, every command succeeds. */
function fakeInstallServer(): void
{
    Process::fake(fn ($process) => match (true) {
        // wp-cli already installed, so the download step is skipped.
        $process->command[0] === 'test' && in_array('-x', $process->command, true) => Process::result(exitCode: 0),
        ($process->command[0] ?? '') === 'mysql' => Process::result(output: '1'),
        default => Process::result(exitCode: 0),
    });
}

function runProvision(Application $application): void
{
    (new ProvisionApplication($application->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );
}

it('installs wordpress end to end after the site is serving', function () {
    fakeSaltService();
    fakeInstallServer();
    $app = wpApp();

    runProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('active');

    // The install runs only after the vhost is live — WordPress writes its own URL into the
    // database during setup. `create_php_pool` gives a PHP site its own FPM pool and user, so it
    // cannot read other sites' configuration.
    expect($app->steps)->toBe([
        'ensure_account', 'create_directory', 'set_ownership', 'placeholder', 'harden_php', 'create_php_pool',
        'write_config', 'test_config', 'reload',
        'create_database', 'download', 'extract', 'configure', 'install_app',
    ]);

    Process::assertRan(fn ($p) => $p->command[0] === 'curl'
        && in_array('https://wordpress.org/latest.tar.gz', $p->command, true));
    Process::assertRan(fn ($p) => $p->command[0] === 'tar' && in_array('--strip-components=1', $p->command, true));
});

it('runs wp-cli under the site own php, not whatever the shebang finds', function () {
    fakeSaltService();
    fakeInstallServer();

    runProvision(wpApp());

    // wp-cli is a phar with `#!/usr/bin/env php`: the shebang may find another PHP version, or
    // none at all on OpenLiteSpeed, where PHP lives in the lsws tree.
    Process::assertRan(function ($p) {
        $command = $p->command;
        $wp = array_search('/usr/local/bin/wp', $command, true);
        $php = array_search('/usr/bin/php8.4', $command, true);

        // Not `$wp - 1`: a run of `-d` pairs (memory limit etc.) sits between the two.
        $between = $php === false ? [] : array_slice($command, $php + 1, $wp - $php - 1);

        $onlyIniFlags = count($between) % 2 === 0
            && collect($between)->every(fn ($argument, $index) => $index % 2 === 0
                ? $argument === '-d'
                : str_contains((string) $argument, '='));

        return $wp !== false
            && in_array('core', $command, true)
            && $php !== false
            && $php < $wp
            && $onlyIniFlags;
    });
});

it('never puts the admin password on a command line', function () {
    fakeSaltService();
    fakeInstallServer();
    $app = wpApp();

    runProvision($app);

    // `ps` is readable by every user on the box.
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), 'Sup3rSecretPassw0rd'));

    // It reaches wp-cli over stdin instead.
    Process::assertRan(fn ($p) => in_array('--prompt=admin_password', $p->command, true)
        && str_contains((string) $p->input, 'Sup3rSecretPassw0rd'));
});

it('runs the installer as the site user, never as the panel', function () {
    fakeSaltService();
    fakeInstallServer();
    $app = wpApp();

    runProvision($app);

    Process::assertRan(fn ($p) => $p->command[0] === 'runuser'
        && $p->command[2] === 'deploy'
        && in_array('core', $p->command, true));
});

it('creates a database and a dedicated user, and writes them into wp-config', function () {
    fakeSaltService();
    fakeInstallServer();
    $app = wpApp();

    runProvision($app);

    $database = Database::where('application_id', $app->id)->with('users')->first();
    expect($database)->not->toBeNull();
    expect($database->users)->toHaveCount(1);

    // The generated password ends up in wp-config.php and nowhere else.
    $password = $database->users->first()->password;

    Process::assertRan(fn ($p) => in_array('tee', $p->command, true)
        && str_contains((string) end($p->command), 'wp-config.php')
        && str_contains((string) $p->input, $database->name)
        && str_contains((string) $p->input, $password));

    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), $password));
});

it('copies the extracted files into the site as the site user, never as root', function () {
    // The document root is not always new ("Retry setup" reuses one), and root's `cp` would
    // write through a link planted at a destination path.
    fakeSaltService();
    fakeInstallServer();
    runProvision(wpApp());

    $unwrap = fn (array $c) => ($c[0] ?? null) === 'sudo' ? array_slice($c, 2) : $c;

    Process::assertNotRan(fn ($p) => ($unwrap($p->command)[0] ?? null) === 'cp');
    Process::assertRan(function ($p) use ($unwrap) {
        $c = $unwrap($p->command);

        return ($c[0] ?? null) === 'runuser' && ($c[2] ?? null) === 'deploy' && ($c[4] ?? null) === 'cp';
    });
});

it('hands the extracted files to the site user, not root', function () {
    fakeSaltService();
    fakeInstallServer();
    $app = wpApp();

    runProvision($app);

    // The extract copy runs elevated, so every file would be root's and the installer's own CLI
    // (running as the site user) could not write; set_ownership runs before the download.
    // The trailing `/.` matters: $documentRoot can be the `current` symlink, and `chown -R` on
    // a symlink argument changes the link, not the release it points at.
    Process::assertRan(function ($p) {
        $args = ($p->command[0] ?? null) === 'sudo' ? array_slice($p->command, 2) : $p->command;

        return ($args[0] ?? null) === 'chown'
            && ($args[1] ?? null) === '-R'
            && ($args[2] ?? null) === 'deploy:deploy'
            && str_ends_with((string) ($args[3] ?? ''), '/.');
    });
});

it('names the database after the slug rather than the domain', function () {
    fakeSaltService();
    fakeInstallServer();

    // The domain would normalize to a long name (every dot becomes an underscore). The slug is
    // the short, unique name the site's own directory already uses.
    $app = sluggedApp('shop', ['name' => 'Shop', 'domain' => 'shop.dipali-store.co.in']);

    runProvision($app);

    $database = Database::where('application_id', $app->id)->with('users')->first();

    expect($database->name)->toMatch('/^shop_[a-z0-9]{6}$/')
        // The user is the same string: one identifier to keep unique, not two.
        ->and($database->users->first()->username)->toBe($database->name);
});

it('does not hand out a name the engine already has', function () {
    fakeSaltService();

    // The engine says the first candidate is taken and the second is free. Without the probe, a
    // collision with an adopted database fails as an opaque create_database error.
    $offered = [];

    Process::fake(function ($process) use (&$offered) {
        if (($process->command[0] ?? '') === 'test') {
            return Process::result(exitCode: 0);
        }

        if (($process->command[0] ?? '') === 'mysql') {
            $sql = (string) $process->input;

            // The availability probe (statements go over stdin, never argv). Match on its EXISTS,
            // not the table: describeDatabase() also reads information_schema.schemata.
            if (str_contains($sql, 'CASE WHEN EXISTS') && str_contains($sql, 'information_schema.schemata')) {
                preg_match("/schema_name = '([^']+)'/", $sql, $m);
                $offered[] = $m[1] ?? '';

                return Process::result(output: count($offered) === 1 ? '0' : '1');
            }

            return Process::result(output: '1');
        }

        return Process::result(exitCode: 0);
    });

    $app = sluggedApp('shop', ['name' => 'Shop', 'domain' => 'shop.example.com']);

    runProvision($app);

    $app->refresh();
    $database = Database::where('application_id', $app->id)->first();

    expect($app->status->value)->toBe('active')
        ->and($offered)->toHaveCount(2)
        ->and($offered[0])->not->toBe($offered[1])
        ->and($database->name)->toBe($offered[1]);
});

it('reports the reference the failure was actually logged under', function () {
    fakeSaltService();

    // Read the log back: the reference the user is given must point at an entry that exists.
    $dir = storage_path('logs/install-reference-'.getmypid());
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0755, true);
    config(['logging.channels.server-ops.path' => $dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');

    Process::fake(function ($process) {
        // Both clients, not just mysql: otherwise the install fails elsewhere on mariadb and the
        // test passes for the wrong reason.
        if (! in_array($process->command[0] ?? '', ['mysql', 'mariadb'], true)) {
            return Process::result(exitCode: 0);
        }

        // Everything answers and the name is free; only CREATE DATABASE is refused, which is
        // where the engine mints the reference under test.
        return str_contains((string) $process->input, 'CREATE DATABASE')
            ? Process::result(errorOutput: 'ERROR 1044 (42000): Access denied', exitCode: 1)
            : Process::result(output: '1');
    });

    $app = sluggedApp('shop', ['name' => 'Shop', 'domain' => 'shop.example.com']);

    runProvision($app);

    $app->refresh();
    $log = File::get($dir.'/server-ops.log');

    // The engine's reference must be reused, not replaced by a fresh uuid that appears in no log.
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('create_database')
        ->and($app->reference)->not->toBeEmpty()
        ->and($log)->toContain($app->reference);

    File::deleteDirectory($dir);
});

it('writes an entry for a failure that had not logged one', function () {
    fakeSaltService();

    $dir = storage_path('logs/install-unlogged-'.getmypid());
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0755, true);
    config(['logging.channels.server-ops.path' => $dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');

    fakeInstallServer();

    // A bug rather than a server refusal: nothing logged, so the reference would name no entry.
    $this->mock(CreateDatabase::class)
        ->shouldReceive('execute')
        ->andThrow(new RuntimeException('unexpected'));

    $app = sluggedApp('shop', ['name' => 'Shop', 'domain' => 'shop.example.com']);

    runProvision($app);

    $app->refresh();

    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('create_database')
        ->and(File::get($dir.'/server-ops.log'))->toContain($app->reference);

    File::deleteDirectory($dir);
});

it('keeps the database user within the length MySQL will accept', function () {
    fakeSaltService();
    fakeInstallServer();

    // No slug, so the name falls back to the domain (how applications created before the slug
    // column look). A nip.io host gives `wordpress_139_59_88_213_nip_io_xxxxxx` (37 chars), but
    // MySQL's user-name limit is 32 and the same string names the database and its user.
    $app = wpApp(['domain' => 'wordpress.139.59.88.213.nip.io']);

    runProvision($app);

    $database = Database::where('application_id', $app->id)->with('users')->first();
    $username = $database->users->first()->username;

    expect(strlen($username))->toBeLessThanOrEqual(32)
        // The random tail keeps two truncated domains apart, so it must survive truncation.
        ->and($username)->toMatch('/_[a-z0-9]{6}$/')
        ->and($username)->toStartWith('wordpress_');
});

it('locks down wp-config.php, which holds live database credentials', function () {
    fakeSaltService();
    fakeInstallServer();
    runProvision(wpApp());

    // By the site user (root's chmod would follow a planted link). 0600, not 0640: the group is
    // not private — on OpenLiteSpeed the web server account is a member of it.
    Process::assertRan(fn ($p) => array_slice($p->command, 0, 6) === ['runuser', '-u', 'deploy', '--', 'chmod', '0600']
        && str_contains((string) end($p->command), 'wp-config.php'));
});

/** The wp-config.php WordPress will read, as written. */
function wpConfigWritten(): string
{
    $config = '';

    Process::fake(function ($process) use (&$config) {
        if (in_array('tee', $process->command, true) && str_contains((string) end($process->command), 'wp-config.php')) {
            $config = (string) $process->input;
        }

        // The name-availability probe: a bare exitCode-0 answers "", which reads as "taken".
        return ($process->command[0] ?? '') === 'mysql'
            ? Process::result(output: '1')
            : Process::result(exitCode: 0);
    });

    runProvision(wpApp());

    return $config;
}

it('writes a moved database port into DB_HOST, which wpdb parses itself', function () {
    fakeSaltService();
    // The connection is editable (PUT /databases/connections/mysql): a managed MySQL on 25060
    // must not get a wp-config naming the default port.
    moveDatabasePort('mysql', 25060);

    expect(wpConfigWritten())->toContain("define('DB_HOST', '127.0.0.1:25060');");
});

it('leaves DB_HOST bare on a stock database, as every existing site has it', function () {
    fakeSaltService();

    // 3306 is what wpdb assumes, so naming it would only change existing sites' configs.
    expect(wpConfigWritten())->toContain("define('DB_HOST', '127.0.0.1');");
});

it('gives every install its own salts', function () {
    // No salt service reachable — the local fallback must still be unique.
    fakeSaltService(reachable: false);

    $configs = [];

    // The fake handler sees every command, so it can capture what was written.
    Process::fake(function ($process) use (&$configs) {
        if (in_array('tee', $process->command, true) && str_contains((string) end($process->command), 'wp-config.php')) {
            preg_match("/define\('AUTH_KEY', '(.*)'\);/U", (string) $process->input, $m);
            $configs[] = $m[1] ?? '';
        }

        // The name-availability probe: a bare exitCode-0 answers "", which reads as "taken".
        return ($process->command[0] ?? '') === 'mysql'
            ? Process::result(output: '1')
            : Process::result(exitCode: 0);
    });

    foreach (['a.example.com', 'b.example.com'] as $domain) {
        runProvision(wpApp(['domain' => $domain]));
    }

    expect($configs)->toHaveCount(2);
    // Shared salts would let a leak on one site forge sessions on another.
    expect($configs[0])->not->toBe($configs[1]);
    expect($configs[0])->not->toBeEmpty();
});

it('fails clearly when no database engine is available', function () {
    fakeSaltService();
    // Engine unreachable — a version probe that never succeeds.
    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'mysql' || ($process->command[0] ?? '') === 'mariadb'
        ? Process::result(exitCode: 1)
        : Process::result(exitCode: 0));

    $app = wpApp();
    runProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('failed');
    expect($app->failed_step)->toBe('create_database');
    // Nothing was downloaded — we stop before touching the web root.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'curl');
});

it('installs wp-cli only when it is missing', function () {
    fakeSaltService();
    Process::fake(fn ($process) => match (true) {
        $process->command[0] === 'test' && in_array('-x', $process->command, true) => Process::result(exitCode: 1),
        // The name-availability probe; "" reads as taken and never converges.
        ($process->command[0] ?? '') === 'mysql' => Process::result(output: '1'),
        default => Process::result(exitCode: 0),
    });

    runProvision(wpApp());

    Process::assertRan(fn ($p) => $p->command[0] === 'curl'
        && in_array('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', $p->command, true));
});

it('skips the installer entirely for site types that have none', function () {
    fakeSaltService();
    fakeInstallServer();

    $app = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Static',
        'slug' => 'static', 'domain' => 'static.example.com',
        'site_type' => 'static', 'serving_profile' => 'static', 'status' => 'pending', 'web_root' => '/',
    ]);

    runProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('active');
    expect($app->steps)->toBe([
        'ensure_account', 'create_directory', 'set_ownership', 'placeholder', 'write_config', 'test_config', 'reload',
    ]);
    expect(Database::where('application_id', $app->id)->count())->toBe(0);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'curl');
});

it('only downloads over https', function () {
    fakeSaltService();
    fakeInstallServer();
    runProvision(wpApp());

    // An http redirect must not be followed into a plaintext download.
    Process::assertRan(fn ($p) => $p->command[0] === 'curl'
        && in_array('--proto', $p->command, true)
        && in_array('=https', $p->command, true)
        && in_array('--proto-redir', $p->command, true));
});
