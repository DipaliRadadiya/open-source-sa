<?php

use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\Database;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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
        'name' => 'Blog',
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
    expect($app->steps)->toBe([
        'create_directory', 'set_ownership', 'placeholder', 'write_config', 'test_config', 'reload',
        'download', 'extract', 'configure', 'install_app',
    ]);

    Process::assertRan(fn ($p) => $p->command[0] === 'curl'
        && in_array('https://wordpress.org/latest.tar.gz', $p->command, true));
    Process::assertRan(fn ($p) => $p->command[0] === 'tar' && in_array('--strip-components=1', $p->command, true));
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

        return $process->command[0] === 'test' && in_array('-x', $process->command, true)
            ? Process::result(exitCode: 0)
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
        default => Process::result(exitCode: 0),
    });

    runProvision(wpApp());

    Process::assertRan(fn ($p) => $p->command[0] === 'curl'
        && in_array('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', $p->command, true));
});

it('skips the installer entirely for site types that have none', function () {
    fakeSaltService();
    fakeInstallServer();

    $app = Application::create([
        'system_user_id' => $this->su->id, 'name' => 'Static', 'domain' => 'static.example.com',
        'site_type' => 'static', 'serving_profile' => 'static', 'status' => 'pending', 'web_root' => '/',
    ]);

    runProvision($app);

    $app->refresh();
    expect($app->status->value)->toBe('active');
    expect($app->steps)->toBe([
        'create_directory', 'set_ownership', 'placeholder', 'write_config', 'test_config', 'reload',
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
