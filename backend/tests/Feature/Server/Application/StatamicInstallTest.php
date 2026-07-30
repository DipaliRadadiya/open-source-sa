<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-stat-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'statuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Statamic site',
        'domain' => 'stat.example.com',
        'site_type' => 'statamic',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/public',
        'status' => 'pending',
        'settings' => [
            'admin_email' => 'admin@example.com',
            'admin_password' => 'StatamicPass1!',
        ],
    ]);

    $this->projectRoot = "{$this->home}/stat.example.com";
});

function installStatamic(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

it('creates no database, because the content lives in files', function () {
    installStatamic();

    // A database would sit empty and its credentials would be a secret
    // nobody needs.
    expect(Database::where('application_id', $this->application->id)->count())->toBe(0);
});

it('serves from public/, not the project root', function () {
    // Serving the root would publish the application source and its .env.
    expect($this->application->web_root)->toBe('/public');

    $composer = collect(installStatamic())
        ->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    // Built into the project root, one level above what is served.
    expect($composer)->toContain('statamic/statamic', $this->projectRoot);
});

it('defaults a new Statamic app to the public directory', function () {
    $type = app(SiteTypeManager::class)->find('statamic');

    // Without this the create request would fall back to the site root and
    // put .env on a URL.
    expect($type->defaultWebRoot())->toBe('/public');
});

it('passes the password as an option, which is the one place we do', function () {
    $install = collect(installStatamic())
        ->first(fn ($run) => in_array('make:user', $run['command'], true));

    // Statamic's only non-interactive path takes --password; its interactive
    // one uses Laravel Prompts, which refuses a pipe and throws. Measured,
    // not assumed — so this is a deliberate exception, not an oversight.
    expect($install['command'])->toContain('--password=StatamicPass1!')
        ->and($install['command'])->toContain('--super')
        ->and($install['command'])->toContain('admin@example.com');
});

it('runs make:user from the project root as the site user', function () {
    $install = collect(installStatamic())
        ->first(fn ($run) => in_array('make:user', $run['command'], true));

    expect($install['path'])->toBe($this->projectRoot)
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'statuser', '--']);
});
