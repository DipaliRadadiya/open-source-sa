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

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Craft site',
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

    $this->projectRoot = "{$this->home}/craft.example.com";
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

    expect($composer)->toContain('craftcms/craft', '--no-interaction')
        // Built into the project root, not the document root — Craft's source
        // lives above the directory the web server serves.
        ->and($composer)->toContain($this->projectRoot);
});

it('serves from web/, so the application source is not published', function () {
    // Pointing the web server at the project root would put `.env`, with the
    // database password in it, on a URL.
    expect($this->application->web_root)->toBe('/web');

    $runs = installCraft();
    $env = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), '.env'));

    expect($env['command'][1])->toBe("{$this->projectRoot}/.env");
});

it('keeps every secret off the command line', function () {
    $runs = installCraft();

    foreach ($runs as $run) {
        $line = implode(' ', $run['command']);
        expect($line)->not->toContain('CraftPass1!')
            ->and($line)->not->toContain('--password');
    }

    // The database password reaches Craft through .env; the admin password
    // through the prompt Craft makes when --password is omitted.
    $env = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), '.env'))['input'];
    expect($env)->toContain('CRAFT_DB_PASSWORD=');

    $install = collect($runs)->first(fn ($run) => in_array('install', $run['command'], true)
        && in_array('craft', $run['command'], true));
    expect($install['input'])->toBe("CraftPass1!\n");
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
