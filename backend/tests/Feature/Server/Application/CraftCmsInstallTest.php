<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-craft-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'crftuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Craft site',
        'slug' => 'craft-site',
        'domain' => 'craft.example.com',
        'site_type' => 'craftcms',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/web',
        'status' => 'pending',
        'settings' => [
            'site_name' => 'My Craft Site',
            'admin_user' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'CraftPass1!',
        ],
    ]);

    $this->projectRoot = "{$this->home}/craft-site/public_html";
});

/**
 * @param  string|null  $engine  the engine the site asked for, on a server
 *                               that has only that one
 */
/**
 * The run that wrote the project's `.env`: `runuser -u <user> -- tee <path>`, so the path is
 * the last argument.
 *
 * @return array{command: array<int, string>, input: string, path: ?string}|null
 */
function craftEnvWrite(ArrayObject $runs): ?array
{
    return collect($runs)->first(function (array $run): bool {
        $command = $run['command'];
        $bare = ($command[0] ?? '') === 'runuser' ? array_slice($command, 4) : $command;

        return ($bare[0] ?? '') === 'tee' && str_ends_with((string) ($bare[1] ?? ''), '.env');
    });
}

function installCraft(?string $engine = null): ArrayObject
{
    $runs = new ArrayObject;

    if ($engine !== null) {
        test()->application->forceFill([
            'settings' => array_merge(test()->application->settings, ['database_engine' => $engine]),
        ])->save();
    }

    Process::fake(function ($process) use ($runs, $engine) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return ($engine === 'postgresql' ? fakePostgresOnlyAnswer($process) : fakeDatabaseAnswer($process))
            ?? Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

it('builds the project with Composer, since Craft ships no tarball', function () {
    $runs = installCraft();

    $composer = collect($runs)->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    expect($composer)->toContain('craftcms/craft', '--no-interaction');
});

it('builds into an empty directory, not the project root Composer would refuse', function () {
    $runs = installCraft();

    $composer = collect($runs)->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];
    $target = $composer[array_search('create-project', $composer, true) + 2];

    // The provisioner has already made {$projectRoot}/web with a placeholder in it, so
    // create-project at the project root would die with "Project directory ... is not empty".
    expect($target)->not->toBe($this->projectRoot)
        ->and($target)->not->toBe("{$this->projectRoot}/web");

    // ...and the build is then copied into the project root, as the site user: root's `cp`
    // would write through a link planted at the destination.
    $copy = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'runuser'
        && ($run['command'][4] ?? '') === 'cp'
        && in_array($this->projectRoot, $run['command'], true));

    expect($copy['command'])->toContain("{$target}/.");
});

it('runs Composer as the site user, never as the panel', function () {
    $composer = collect(installCraft())
        ->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    // Composer runs the project's own post-install scripts: as the panel they would run as root.
    expect(array_slice($composer, 0, 4))->toBe(['runuser', '-u', 'crftuser', '--']);
});

it('serves from web/, so the application source is not published', function () {
    // Serving the project root would put `.env` (with the database password) on a URL.
    expect($this->application->web_root)->toBe('/web');

    $runs = installCraft();
    $env = craftEnvWrite($runs);

    expect(end($env['command']))->toBe("{$this->projectRoot}/.env")
        // As the site user: root's `tee` would follow a `.env` replaced by a link.
        ->and(array_slice($env['command'], 0, 5))->toBe(['runuser', '-u', $this->application->systemUser->username, '--', 'tee']);
});

it('keeps the database credentials off the command line', function () {
    // The database credentials go into `.env`, which Craft reads itself, so `craft install`
    // is never told them and they stay off argv.
    $runs = installCraft();

    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('CraftDbPass1!');
    }

    $env = craftEnvWrite($runs)['input'];
    expect($env)->toContain('CRAFT_DB_PASSWORD=');
});

it('passes the admin password as an option, because the prompt no longer reads a pipe', function () {
    // Deliberate exception: the admin password is passed on argv, not piped. Without a TTY
    // Craft never prompts, takes the password as empty and fails validation. The cost is that
    // `ps` shows it to every user while the install runs.
    $install = collect(installCraft())->first(fn ($run) => in_array('install', $run['command'], true)
        && in_array('craft', $run['command'], true));

    expect($install['command'])->toContain('--password=CraftPass1!')
        // Nothing is left on stdin for a prompt.
        ->and($install['input'])->toBe('');
});

it('generates a distinct security key and app id per installation', function () {
    $envOf = fn (ArrayObject $runs) => craftEnvWrite($runs)['input'];

    $first = $envOf(installCraft());
    $second = $envOf(installCraft());

    preg_match('/CRAFT_SECURITY_KEY=(\S+)/', $first, $a);
    preg_match('/CRAFT_SECURITY_KEY=(\S+)/', $second, $b);

    // The security key signs and encrypts everything Craft stores: shared between installs,
    // one site's data would be readable from another's.
    expect($a[1])->not->toBe($b[1])
        ->and(strlen($a[1]))->toBe(32);
});

it('runs Craft from the project root, as the site user', function () {
    $install = collect(installCraft())->first(fn ($run) => in_array('craft', $run['command'], true)
        && in_array('install', $run['command'], true));

    expect($install['path'])->toBe($this->projectRoot)
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'crftuser', '--']);
});

it('tells Craft to speak PostgreSQL when that is the database it was given', function () {
    $runs = installCraft('postgresql');

    $env = craftEnvWrite($runs)['input'];

    // `pgsql` is DbConfig::DRIVER_PGSQL: Craft validates the value against its own constants.
    expect($env)->toContain('CRAFT_DB_DRIVER=pgsql')
        // Without this, a PostgreSQL database would be handed to a site configured for MySQL.
        ->not->toContain('CRAFT_DB_DRIVER=mysql')
        // The real port from the engine's connection record, not MySQL's.
        ->toContain('CRAFT_DB_PORT=5432')
        // PostgreSQL's default schema.
        ->toContain('CRAFT_DB_SCHEMA=public');
});

it('still tells Craft to speak MySQL on a MySQL server', function () {
    // Existing Craft sites are on MySQL: a driver that only ever produced `pgsql` would break
    // them on the next install.
    $env = craftEnvWrite(installCraft())['input'];

    expect($env)->toContain('CRAFT_DB_DRIVER=mysql')
        ->not->toContain('CRAFT_DB_DRIVER=pgsql');
});
