<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\InstallerManager;
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

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Statamic site',
        'slug' => 'statamic-site',
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

    $this->projectRoot = "{$this->home}/statamic-site/public_html";
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

    $runs = installStatamic();

    $composer = collect($runs)->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    expect($composer)->toContain('statamic/statamic');

    // Built one level above what is served — but not *into* the project root,
    // which already holds public/ and would make Composer refuse. It is copied
    // there instead.
    $copy = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'cp'
        && in_array($this->projectRoot, $run['command'], true));

    expect($copy)->not->toBeNull();
});

it('builds into an empty directory, not the project root Composer would refuse', function () {
    $composer = collect(installStatamic())
        ->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    $target = $composer[array_search('create-project', $composer, true) + 2];

    expect($target)->not->toBe($this->projectRoot)
        ->and($target)->not->toBe("{$this->projectRoot}/public");
});

it('runs Composer as the site user, never as the panel', function () {
    $composer = collect(installStatamic())
        ->first(fn ($run) => in_array('create-project', $run['command'], true))['command'];

    // Statamic's create-project runs post-install scripts; as the panel they
    // would run as root.
    expect(array_slice($composer, 0, 4))->toBe(['runuser', '-u', 'statuser', '--']);
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

it('points APP_URL at the site, not the example file\'s localhost', function () {
    // `composer create-project` copies Statamic's .env.example, which ships
    // APP_URL=http://localhost:8000. It is the one value in that file only the
    // panel knows, and Laravel builds password resets, queued mail, sitemaps
    // and Statamic's own asset and control-panel URLs from it.
    $runs = installStatamic();

    $written = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'tee'
        && str_contains($run['input'], 'APP_URL='));

    expect($written)->not->toBeNull()
        ->and($written['input'])->toContain('APP_URL=http://stat.example.com')
        ->and($written['input'])->not->toContain('localhost:8000');
});

it('sets it before the first user is made, so the first login lands on the site', function () {
    $runs = collect(installStatamic())->values();

    $urlWrite = $runs->search(fn ($run) => ($run['command'][0] ?? '') === 'tee'
        && str_contains($run['input'], 'APP_URL='));
    $makeUser = $runs->search(fn ($run) => in_array('make:user', $run['command'], true));

    expect($urlWrite)->not->toBeFalse()
        ->and($makeUser)->not->toBeFalse()
        ->and($urlWrite)->toBeLessThan($makeUser);
});

it('follows the domain when it changes, and clears the config cache that would outlive it', function () {
    // A compiled bootstrap/cache/config.php is read *instead of* .env, so
    // without the clear the site serves the old URL from a file that no longer
    // says it.
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return ($process->command[0] ?? '') === 'cat'
            ? Process::result(output: "APP_NAME=Statamic\nAPP_URL=http://stat.example.com\n")
            : Process::result(exitCode: 0);
    });

    app(InstallerManager::class)->syncUrl($this->application, 'https://new.example.com');

    $written = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'tee');

    expect($written['input'])->toContain('APP_URL=https://new.example.com')
        // The rest of the file is the user's and is not rebuilt from parsed
        // pairs — comments and ordering would not survive that.
        ->and($written['input'])->toContain('APP_NAME=Statamic');

    expect(collect($runs)->contains(fn ($run) => in_array('config:clear', $run['command'], true)))->toBeTrue();
});
