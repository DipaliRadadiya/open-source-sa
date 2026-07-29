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

    $this->home = sys_get_temp_dir().'/sv-oss-nc-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'ncuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Files',
        'domain' => 'cloud.example.com',
        'site_type' => 'nextcloud',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'admin_user' => 'ncadmin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'AdminPassw0rd!',
        ],
    ]);

    $this->docRoot = "{$this->home}/cloud.example.com";
});

/**
 * Provision, capturing every command with its stdin and working directory.
 */
function installNextcloud(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = [
            'command' => $process->command,
            'input' => (string) $process->input,
            'path' => $process->path,
        ];

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function occRun(ArrayObject $runs, string $subcommand): ?array
{
    return collect($runs)->first(fn ($run) => in_array($subcommand, $run['command'], true));
}

it('never puts a password on the command line', function () {
    $runs = installNextcloud();

    $install = occRun($runs, 'maintenance:install');

    // `ps` is readable by every user on the machine, so an admin password or a
    // database password passed as an argument is a password handed out.
    expect($install['command'])->not->toContain('--admin-pass')
        ->and($install['command'])->not->toContain('--database-pass');

    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))
            ->not->toContain('AdminPassw0rd!');
    }
});

it('answers occ\'s prompts on stdin, database password first', function () {
    $runs = installNextcloud();

    $install = occRun($runs, 'maintenance:install');

    // Omitting the options makes occ ask, in this order. Getting the order
    // wrong sets the admin password to the database password and vice versa —
    // an install that succeeds and locks the user out.
    $lines = explode("\n", $install['input']);
    expect($lines[1])->toBe('AdminPassw0rd!')
        ->and($lines[0])->not->toBe('')
        ->and($lines[0])->not->toBe('AdminPassw0rd!');
});

it('runs occ from Nextcloud\'s own directory', function () {
    $runs = installNextcloud();

    // Upstream is explicit: run maintenance:install from anywhere else and it
    // dies with a PHP fatal error.
    expect(occRun($runs, 'maintenance:install')['path'])->toBe($this->docRoot);
});

it('runs occ as the site user, not as the panel', function () {
    $runs = installNextcloud();

    $install = occRun($runs, 'maintenance:install');

    // occ writes files that the web server then has to own; running it as
    // anyone else leaves an installation the site cannot use.
    expect(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'ncuser', '--'])
        ->and($install['command'][4])->toBe('/usr/bin/php8.4');
});

it('keeps user files out of the web root', function () {
    $runs = installNextcloud();

    $dataDir = "{$this->home}/nextcloud-data";
    $commands = collect($runs)->pluck('command');

    // The data directory holds every file every user uploads. Under the
    // document root, each one is a URL.
    expect(occRun($runs, 'maintenance:install')['command'])->toContain('--data-dir', $dataDir)
        ->and($commands)->toContain(['mkdir', '-p', $dataDir])
        ->and($commands)->toContain(['chmod', '0750', $dataDir]);
});

it('trusts the site\'s own domain, or the site refuses every visitor', function () {
    $runs = installNextcloud();

    // Installed from the command line there is no request to learn the
    // hostname from, so Nextcloud trusts only localhost and answers everyone
    // else with a refusal page.
    $trust = occRun($runs, 'trusted_domains');
    expect($trust['command'])->toContain('--value=cloud.example.com')
        ->and($trust['path'])->toBe($this->docRoot);

    $cliUrl = occRun($runs, 'overwrite.cli.url');
    expect($cliUrl['command'])->toContain('--value=https://cloud.example.com');
});

it('lets tar work out the compression, since this one ships bzip2', function () {
    $runs = installNextcloud();

    $tar = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'tar')['command'];

    // Upstream publishes bzip2 and zip only. `-xzf` would fail on every
    // Nextcloud release ever made.
    expect($tar)->toContain('-xf')
        ->and($tar)->not->toContain('-xzf');
});

it('allows longer than the shared default for a 280 MB download', function () {
    $runs = installNextcloud();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];
    $maxTime = (int) $curl[array_search('--max-time', $curl, true) + 1];

    // The shared 300s default would time this out on any ordinary connection.
    expect($maxTime)->toBe(1800);
});
