<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-adminer-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'adminuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Database browser',
        'slug' => 'database-browser',
        'domain' => 'adminer.example.com',
        'site_type' => 'adminer',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
    ]);

    Http::preventStrayRequests();
});

function installAdminer(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function adminerCurl(ArrayObject $runs): array
{
    return collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];
}

it('installs the newest stable release GitHub lists', function () {
    Http::fake(['api.github.com/repos/vrana/adminer/releases*' => Http::response([
        ['tag_name' => 'v6.2.0-rc1', 'draft' => false, 'prerelease' => true],
        ['tag_name' => 'v6.1.2', 'draft' => false, 'prerelease' => false],
        ['tag_name' => 'v5.4.1', 'draft' => false, 'prerelease' => false],
    ])]);

    $curl = adminerCurl(installAdminer());

    expect($curl)->toContain('https://github.com/vrana/adminer/releases/download/v6.1.2/adminer-6.1.2.php')
        ->and($curl)->toContain('--proto', '=https')
        ->and($curl)->toContain('--proto-redir', '=https')
        // Without --fail a GitHub error page would be saved, and served, as adminer.php.
        ->and($curl)->toContain('--fail');
});

it('falls back to the configured version when GitHub cannot be asked', function () {
    Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);
    config(['server.installers.adminer.version' => '6.0.9']);

    expect(adminerCurl(installAdminer()))->toContain('https://github.com/vrana/adminer/releases/download/v6.0.9/adminer-6.0.9.php');
});

it('creates no database for a tool that reads the ones already there', function () {
    Http::fake(['api.github.com/*' => Http::response([])]);

    installAdminer();

    expect(Database::where('application_id', $this->application->id)->count())->toBe(0);
});

it('lays down upstream\'s file untouched, with the panel\'s wrapper and sign-in script beside it', function () {
    Http::fake(['api.github.com/*' => Http::response([])]);

    $runs = installAdminer();
    $root = "{$this->home}/database-browser/public_html";
    // The last write wins: the provisioner's placeholder goes to index.php
    // first, and the installer runs after it.
    $wrote = fn (string $path) => collect($runs)->last(fn ($run) => $run['command'] === ['runuser', '-u', 'adminuser', '--', 'tee', $path]);

    // Downloaded under its own name, so index.php is free for the wrapper —
    // and the wrapper is written over the provisioner's placeholder index.php.
    $curl = adminerCurl($runs);
    expect($curl[array_search('--output', $curl, true) + 1])->toEndWith('/src/adminer.php')
        ->and($wrote("{$root}/index.php"))->not->toBeNull()
        ->and($wrote("{$root}/index.php")['input'])->toContain("include __DIR__.'/adminer.php';")
        ->and($wrote("{$root}/sso.php"))->not->toBeNull();
});
