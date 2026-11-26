<?php

use App\Models\Application;
use App\Models\Cronjob;
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

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Files',
        'slug' => 'files',
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

    $this->docRoot = "{$this->home}/files/public_html";
});

/**
 * Provision, capturing every command with its stdin and working directory.
 */
/**
 * @param  int|null  $port  a port the engine is NOT assumed to be on
 */
function installNextcloud(?string $engine = null, ?int $port = null): ArrayObject
{
    $runs = new ArrayObject;

    if ($port !== null) {
        moveDatabasePort($engine ?? 'mysql', $port);
    }

    if ($engine !== null) {
        test()->application->forceFill([
            'settings' => array_merge(test()->application->settings, ['database_engine' => $engine]),
        ])->save();
    }

    Process::fake(function ($process) use ($runs, $engine) {
        $runs[] = [
            'command' => $process->command,
            'input' => (string) $process->input,
            'path' => $process->path,
        ];

        return ($engine === 'postgresql' ? fakePostgresOnlyAnswer($process) : fakeDatabaseAnswer($process))
            ?? Process::result(exitCode: 0);
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

    // `ps` is readable by every user, so a password passed as an argument is handed out.
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

    // Omitting the options makes occ ask, in this order. A wrong order swaps the admin and
    // database passwords: the install succeeds and locks the user out.
    $lines = explode("\n", $install['input']);
    expect($lines[1])->toBe('AdminPassw0rd!')
        ->and($lines[0])->not->toBe('')
        ->and($lines[0])->not->toBe('AdminPassw0rd!');
});

it('runs occ from Nextcloud\'s own directory', function () {
    $runs = installNextcloud();

    // maintenance:install dies with a PHP fatal error unless run from the install directory.
    expect(occRun($runs, 'maintenance:install')['path'])->toBe($this->docRoot);
});

it('runs occ as the site user, not as the panel', function () {
    $runs = installNextcloud();

    $install = occRun($runs, 'maintenance:install');

    // occ writes files the web server must own: run as anyone else, the site cannot use them.
    expect(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'ncuser', '--'])
        ->and($install['command'][4])->toBe('/usr/bin/php8.4');
});

it('keeps user files out of the web root', function () {
    $runs = installNextcloud();

    // `{home}/{slug}/nextcloud-data`: outside the web root, inside the site.
    $dataDir = "{$this->home}/files/nextcloud-data";
    $commands = collect($runs)->pluck('command');

    // The data directory holds every uploaded file: under the document root, each is a URL.
    expect(occRun($runs, 'maintenance:install')['command'])->toContain('--data-dir', $dataDir)
        ->and($commands)->toContain(['mkdir', '-p', $dataDir])
        ->and($commands)->toContain(['chmod', '0750', $dataDir]);
});

it('trusts the site\'s own domain, or the site refuses every visitor', function () {
    $runs = installNextcloud();

    // Installed from the command line there is no request to learn the hostname from, so
    // Nextcloud trusts only localhost and refuses everyone else.
    $trust = occRun($runs, 'trusted_domains');
    expect($trust['command'])->toContain('--value=cloud.example.com')
        ->and($trust['path'])->toBe($this->docRoot);

    $cliUrl = occRun($runs, 'overwrite.cli.url');
    // Installation happens before optional certificate issuance: persist only what the vhost
    // can serve now; the certificate lifecycle promotes it.
    expect($cliUrl['command'])->toContain('--value=http://cloud.example.com');
});

it('tells Nextcloud to write its config.php private to the site user', function () {
    // Nextcloud chmods config.php to `configfilemode` on every write (0640 by default), which
    // must stay readable by the web server account on OpenLiteSpeed.
    $this->application->forceFill(['isolated_at' => now()])->save();
    $runs = installNextcloud();

    $mode = occRun($runs, 'configfilemode');

    expect($mode)->not->toBeNull()
        ->and($mode['command'])->toContain('--value=384') // 0600
        ->and($mode['command'])->toContain('--type=integer')
        ->and($mode['path'])->toBe($this->docRoot);
});

it('takes the zip, not the bzip2 tarball', function () {
    $runs = installNextcloud();

    $extract = collect($runs)
        ->first(fn ($run) => in_array($run['command'][0] ?? '', ['unzip', 'tar'], true))['command'];

    // Upstream publishes bzip2 and zip only. The tarball needs `lbzip2`, which a lean Debian
    // image lacks (`tar` only *Suggests* bzip2); `unzip` is already required.
    expect($extract[0])->toBe('unzip');

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];
    expect(end($curl))->toEndWith('.zip');
});

it('copies out of the wrapping directory the zip ships', function () {
    $runs = installNextcloud();

    // Run as the site user (`runuser -u <user> -- cp …`): the copy's own arguments start four
    // places in.
    $copy = array_slice(collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'runuser'
        && ($run['command'][4] ?? '') === 'cp')['command'], 4);

    // The zip's entries start at `nextcloud/` and `unzip` has no `--strip-components`. Copying
    // from the extract root would put the application one directory below the web root.
    expect($copy[2])->toEndWith('/src/nextcloud/.');
});

it('allows longer than the shared default for a 280 MB download', function () {
    $runs = installNextcloud();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];
    $maxTime = (int) $curl[array_search('--max-time', $curl, true) + 1];

    // The shared 300s default would time this out on any ordinary connection.
    expect($maxTime)->toBe(1800);
});

it('tells occ which database the site was actually given, not a configured constant', function () {
    // `--database` must follow the site's engine, not config alone: occ handed `mysql` for a
    // PostgreSQL database dies inside its own install, past where acceptedEngines() could refuse.
    $install = occRun(installNextcloud('postgresql'), 'maintenance:install');

    // `pgsql` is the value occ's own Install command accepts.
    expect($install['command'])->toContain('pgsql')
        ->not->toContain('mysql');
});

it('passes a moved MySQL port, which occ does have an option for', function () {
    $command = occRun(installNextcloud(null, 25060), 'maintenance:install')['command'];
    $at = array_search('--database-port', $command, true);

    expect($at)->not->toBeFalse()
        // From the engine's connection record, not a literal.
        ->and($command[$at + 1])->toBe('25060');
});

it('passes a moved PostgreSQL port too', function () {
    $command = occRun(installNextcloud('postgresql', 6432), 'maintenance:install')['command'];
    $at = array_search('--database-port', $command, true);

    expect($at)->not->toBeFalse()
        ->and($command[$at + 1])->toBe('6432');
});

it('leaves the stock MySQL command line exactly as it was', function () {
    // The driver still comes from config, and no port option appears: 3306 is what occ assumes,
    // so naming it would change every existing install for nothing.
    $command = occRun(installNextcloud(), 'maintenance:install')['command'];

    expect($command)->toContain('mysql')
        ->not->toContain('pgsql')
        ->not->toContain('--database-port');
});

it('leaves a stock PostgreSQL command line without a port as well', function () {
    $command = occRun(installNextcloud('postgresql'), 'maintenance:install')['command'];

    expect($command)->toContain('pgsql')
        ->not->toContain('--database-port');
});

it('schedules its background jobs on the site\'s own PHP, and only once', function () {
    // Without the cron entry, Nextcloud reports "background jobs last ran 56 years ago".
    $runs = installNextcloud();

    expect(occRun($runs, 'background:cron'))->not->toBeNull();

    $job = Cronjob::query()->sole();

    // The version's binary, not `php` (the server default).
    expect($job->command)->toBe('/usr/bin/php8.4 -f '.$this->application->documentRoot().'/cron.php')
        ->and($job->expression)->toBe('*/5 * * * *')
        ->and($job->application_id)->toBe($this->application->id)
        // The panel's own job for the site, so it goes with the site.
        ->and($job->application_owned)->toBeTrue();

    // Retry Setup runs the installer again.
    installNextcloud();

    expect(Cronjob::query()->count())->toBe(1);
});
