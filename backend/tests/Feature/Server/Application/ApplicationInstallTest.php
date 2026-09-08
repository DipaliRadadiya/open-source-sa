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

/**
 * The salt service. Each test opts in, because whether it answers is itself
 * something worth testing — an install must not depend on it being up.
 */
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
 * `slug` is deliberately not fillable — {@see CreateApplication} sets it with
 * `forceCreate` because it names the web-server config file, and a caller
 * choosing that is a caller choosing which file the panel overwrites. So a
 * test that passes it to `create()` gets a silent null, which is exactly how
 * the first version of this file failed.
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

    // The install runs only after the vhost is live — WordPress writes its own
    // URL into the database during setup.
    // `create_database` is in this list now. It was always in the documented
    // one and never emitted, because the step lists were assembled by hand in
    // each installer and the database is created by the manager above them.
    // `create_php_pool` sits between ownership and the vhost: a PHP site gets
    // its own FPM pool, running as its own Linux user, before anything is
    // served from it. Without that step every PHP site ran as the shared
    // www-data and could read every other site's configuration.
    expect($app->steps)->toBe([
        'check_account', 'create_directory', 'placeholder', 'set_ownership', 'create_php_pool',
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

    // wp-cli is a phar with `#!/usr/bin/env php`. Trusting that means the
    // site's own PHP version is not necessarily the one that installs it —
    // and on an OpenLiteSpeed box, where PHP lives in the lsws tree, there
    // may be no system `php` for the shebang to find at all.
    Process::assertRan(function ($p) {
        $command = $p->command;
        $wp = array_search('/usr/local/bin/wp', $command, true);

        return $wp !== false
            && in_array('core', $command, true)
            && ($command[$wp - 1] ?? '') === '/usr/bin/php8.4';
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

    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        && str_contains((string) $p->command[1], 'wp-config.php')
        && str_contains((string) $p->input, $database->name)
        && str_contains((string) $p->input, $password));

    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), $password));
});

it('hands the extracted files to the site user, not root', function () {
    fakeSaltService();
    fakeInstallServer();
    $app = wpApp();

    runProvision($app);

    // The extract copy runs elevated, so without this every file is root's and
    // the installer's own CLI -- which runs as the site user -- cannot write.
    // Mautic surfaced it as "Unable to create the cache directory
    // (.../var/cache/prod)"; provisioning's set_ownership step cannot cover it
    // because that runs before the download.
    //
    // The trailing `/.` matters: $documentRoot can be the `current` symlink,
    // and `chown -R` on a symlink argument changes the link rather than
    // descending into the release it points at.
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

    // The domain normalizes to `shop_dipali_store_co_in` -- 23 characters of
    // hostname before the random tail, because every dot becomes an
    // underscore. The slug is the short, unique name the site's own directory
    // already uses, so `/home/deploy/shop` and `shop_xxxxxx` read together.
    $app = sluggedApp('shop', ['name' => 'Shop', 'domain' => 'shop.dipali-store.co.in']);

    runProvision($app);

    $database = Database::where('application_id', $app->id)->with('users')->first();

    expect($database->name)->toMatch('/^shop_[a-z0-9]{6}$/')
        // The user is the same string; naming them apart would mean two
        // identifiers to keep unique instead of one.
        ->and($database->users->first()->username)->toBe($database->name);
});

it('does not hand out a name the engine already has', function () {
    fakeSaltService();

    // The engine says the first candidate is taken and the second is free.
    // Before this the primary path called `generate()`, which asked nobody --
    // so a collision with an adopted database surfaced from the catch in
    // provisionDatabase as an opaque create_database failure with no cause.
    $offered = [];

    Process::fake(function ($process) use (&$offered) {
        if (($process->command[0] ?? '') === 'test') {
            return Process::result(exitCode: 0);
        }

        if (($process->command[0] ?? '') === 'mysql') {
            $sql = (string) $process->input;

            // The availability probe -- statements go over stdin, never argv.
            if (str_contains($sql, 'information_schema.schemata')) {
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

    // Read the log back rather than trust the value: the claim is that the
    // reference the user is given points at an entry that exists.
    $dir = storage_path('logs/install-reference-'.getmypid());
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0755, true);
    config(['logging.channels.server-ops.path' => $dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');

    Process::fake(function ($process) {
        // Both clients, not just mysql: failing every mysql statement takes
        // the engine out of the running entirely, and the install then fails
        // somewhere else on mariadb -- passing for the wrong reason.
        if (! in_array($process->command[0] ?? '', ['mysql', 'mariadb'], true)) {
            return Process::result(exitCode: 0);
        }

        // Everything answers, the name is free, and creating it is the one
        // thing that fails -- a real CREATE DATABASE refusal, which is where
        // the engine mints the reference this test is about.
        return str_contains((string) $process->input, 'CREATE DATABASE')
            ? Process::result(errorOutput: 'ERROR 1044 (42000): Access denied', exitCode: 1)
            : Process::result(output: '1');
    });

    $app = sluggedApp('shop', ['name' => 'Shop', 'domain' => 'shop.example.com']);

    runProvision($app);

    $app->refresh();
    $log = File::get($dir.'/server-ops.log');

    // Before this, the catch minted a fresh uuid and dropped the engine's --
    // so the id handed to the user appeared in no log at all, and the one the
    // entry was written under was thrown away.
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

    // A bug rather than a server refusal: nothing ran, so nothing logged, and
    // the reference minted for it would otherwise name no entry at all -- the
    // same defect as the uuid, for the failures hardest to diagnose.
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

    // No slug on this row, so the name falls back to the domain -- which is
    // what every application created before the slug column looks like, and
    // the case the length cap exists for.
    //
    // A nip.io host, which is how every IP-addressed test site is reached and
    // is long before anyone has typed a real domain. This one produced
    // `wordpress_139_59_88_213_nip_io_xqolim` (37 chars) and killed the
    // install at create_database with MySQL's "ERROR 1470 ... is too long for
    // user name" -- 32 is the hard limit, and the same string names both the
    // database and its user.
    $app = wpApp(['domain' => 'wordpress.139.59.88.213.nip.io']);

    runProvision($app);

    $database = Database::where('application_id', $app->id)->with('users')->first();
    $username = $database->users->first()->username;

    expect(strlen($username))->toBeLessThanOrEqual(32)
        // The random tail is what keeps two truncated domains apart, so it
        // must survive the truncation rather than being cut off by it.
        ->and($username)->toMatch('/_[a-z0-9]{6}$/')
        ->and($username)->toStartWith('wordpress_');
});

it('locks down wp-config.php, which holds live database credentials', function () {
    fakeSaltService();
    fakeInstallServer();
    runProvision(wpApp());

    Process::assertRan(fn ($p) => $p->command[0] === 'chmod'
        && $p->command[1] === '0640'
        && str_contains((string) $p->command[2], 'wp-config.php'));
});

it('gives every install its own salts', function () {
    // No salt service reachable — the local fallback must still be unique.
    fakeSaltService(reachable: false);

    $configs = [];

    // The fake handler sees every command, so it can capture what was written.
    Process::fake(function ($process) use (&$configs) {
        if ($process->command[0] === 'tee' && str_contains((string) $process->command[1], 'wp-config.php')) {
            preg_match("/define\('AUTH_KEY', '(.*)'\);/U", (string) $process->input, $m);
            $configs[] = $m[1] ?? '';
        }

        // The name-availability probe. A bare exitCode-0 answers "" here,
        // which reads as "taken" and exhausts the allocator, so the install
        // fails before it writes a config at all.
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
        'check_account', 'create_directory', 'placeholder', 'set_ownership', 'write_config', 'test_config', 'reload',
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
