<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-mautic-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'mtcuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Marketing',
        'slug' => 'marketing',
        'domain' => 'mautic.example.com',
        'site_type' => 'mautic',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'admin_first_name' => 'Ada',
            'admin_last_name' => 'Lovelace',
            'admin_user' => 'ada',
            'admin_email' => 'ada@example.com',
            'admin_password' => 'MauticPass1!',
        ],
    ]);

    Http::fake(['api.github.com/*' => Http::response(['assets' => [
        ['browser_download_url' => 'https://github.com/mautic/mautic/releases/download/7.1.3/7.1.3-update.zip'],
        ['browser_download_url' => 'https://github.com/mautic/mautic/releases/download/7.1.3/7.1.3.zip'],
    ]])]);
});

function installMautic(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function mauticLocalConfig(ArrayObject $runs): string
{
    return collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'local.php'))['input'];
}

it('runs the install with no secret on the command line at all', function () {
    $runs = installMautic();

    // mautic:install takes every credential as an option and never prompts —
    // but it merges local.php first, so nothing has to be argued for.
    foreach ($runs as $run) {
        $line = implode(' ', $run['command']);
        expect($line)->not->toContain('MauticPass1!')
            ->and($line)->not->toContain('--db_password')
            ->and($line)->not->toContain('--admin_password');
    }
});

it('puts both the database and the administrator into local.php', function () {
    $config = mauticLocalConfig(installMautic());

    expect($config)->toStartWith('<?php')
        ->and($config)->toContain("'db_password' => ")
        ->and($config)->toContain("'admin_password' => 'MauticPass1!'")
        ->and($config)->toContain("'admin_email' => 'ada@example.com'")
        ->and($config)->toContain("'site_url' => 'https://mautic.example.com'");

    $path = tempnam(sys_get_temp_dir(), 'mtc').'.php';
    file_put_contents($path, $config);
    exec('php -l '.escapeshellarg($path).' 2>&1', $out, $status);
    expect($status)->toBe(0);
    @unlink($path);
});

it('unzips, because Mautic publishes no tarball', function () {
    $runs = installMautic();

    $extract = collect($runs)->first(fn ($run) => in_array($run['command'][0] ?? '', ['unzip', 'tar'], true))['command'];

    expect($extract[0])->toBe('unzip')
        // The archive is flat — entries start at `.env.test` — so there is
        // nothing to strip even if unzip could.
        ->and($extract)->not->toContain('--strip-components=1');
});

it('takes the full package, not the update package', function () {
    $runs = installMautic();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    // The update package carries only changed files and would unpack into an
    // unusable site.
    expect(end($curl))->toBe('https://github.com/mautic/mautic/releases/download/7.1.3/7.1.3.zip');
});

it('runs the installer from the site directory as the site user', function () {
    $runs = installMautic();

    $install = collect($runs)->first(fn ($run) => in_array('mautic:install', $run['command'], true));

    expect($install['path'])->toBe("{$this->home}/marketing/public_html")
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'mtcuser', '--'])
        ->and($install['command'])->toContain('--no-interaction');
});
