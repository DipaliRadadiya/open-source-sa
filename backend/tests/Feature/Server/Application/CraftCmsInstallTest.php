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

function installCraft(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return Process::result(exitCode: 0);
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

    // The provisioner has already made {$projectRoot}/web and written a
    // placeholder into it, so create-project pointed at the project root dies
    // with "Project directory ... is not empty" — which is what made one-click
    // Craft fail every single time.
    expect($target)->not->toBe($this->projectRoot)
        ->and($target)->not->toBe("{$this->projectRoot}/web");

    // ...and the build is then copied into the project root.
    $copy = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'cp'
        && in_array($this->projectRoot, $run['command'], true));

    expect($copy['command'])->toContain("{$target}/.");
});

it('runs Composer as the site user, never as the panel', function () {
    $composer = collect(installCraft())
        ->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    // Composer executes the project's own post-install scripts; run as the
    // panel they would run as root.
    expect(array_slice($composer, 0, 4))->toBe(['runuser', '-u', 'crftuser', '--']);
});

it('serves from web/, so the application source is not published', function () {
    // Pointing the web server at the project root would put `.env`, with the
    // database password in it, on a URL.
    expect($this->application->web_root)->toBe('/web');

    $runs = installCraft();
    $env = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), '.env'));

    expect($env['command'][1])->toBe("{$this->projectRoot}/.env");
});

it('keeps the database credentials off the command line', function () {
    // Two kinds of secret, and only this one can still be kept off argv. The
    // database credentials go into `.env`, which Craft reads for itself, so
    // `craft install` is never told them.
    $runs = installCraft();

    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('CraftDbPass1!');
    }

    $env = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), '.env'))['input'];
    expect($env)->toContain('CRAFT_DB_PASSWORD=');
});

it('passes the admin password as an option, because the prompt no longer reads a pipe', function () {
    // It used to be piped: omitting --password made Craft prompt, and Yii's
    // prompt was a plain read from stdin. With stdin not a TTY the prompt is
    // never made, the password is taken as empty, and the install dies on
    // Craft's own validation of a value nobody supplied — naming an option the
    // command line did not contain:
    //
    //     Invalid options:
    //      --password: New Password should contain at least 6 characters.
    //
    // So this is a deliberate exception, asserted so nobody restores the pipe
    // by reading the rule without the reason. The cost is real: `ps` shows it
    // to every user on the box while the install runs.
    $install = collect(installCraft())->first(fn ($run) => in_array('install', $run['command'], true)
        && in_array('craft', $run['command'], true));

    expect($install['command'])->toContain('--password=CraftPass1!')
        // Nothing left on stdin for a prompt that is not going to be made.
        ->and($install['input'])->toBe('');
});

it('generates a distinct security key and app id per installation', function () {
    $envOf = fn (ArrayObject $runs) => collect($runs)
        ->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), '.env'))['input'];

    $first = $envOf(installCraft());
    $second = $envOf(installCraft());

    preg_match('/CRAFT_SECURITY_KEY=(\S+)/', $first, $a);
    preg_match('/CRAFT_SECURITY_KEY=(\S+)/', $second, $b);

    // The security key signs and encrypts everything Craft stores; shared
    // between installs, one site's data is readable from another's.
    expect($a[1])->not->toBe($b[1])
        ->and(strlen($a[1]))->toBe(32);
});

it('runs Craft from the project root, as the site user', function () {
    $install = collect(installCraft())->first(fn ($run) => in_array('craft', $run['command'], true)
        && in_array('install', $run['command'], true));

    expect($install['path'])->toBe($this->projectRoot)
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'crftuser', '--']);
});
