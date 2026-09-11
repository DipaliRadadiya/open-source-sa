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
function installNextcloud(?string $engine = null): ArrayObject
{
    $runs = new ArrayObject;

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

    // `{home}/{slug}/nextcloud-data`: outside the web root, inside the site.
    $dataDir = "{$this->home}/files/nextcloud-data";
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
    // Installation happens before optional certificate issuance. Persist only
    // what the vhost can serve now; the certificate lifecycle promotes it.
    expect($cliUrl['command'])->toContain('--value=http://cloud.example.com');
});

it('takes the zip, not the bzip2 tarball', function () {
    $runs = installNextcloud();

    $extract = collect($runs)
        ->first(fn ($run) => in_array($run['command'][0] ?? '', ['unzip', 'tar'], true))['command'];

    // Upstream publishes bzip2 and zip only — no gzip. The tarball was the
    // original choice and it cost a server: Debian's `tar` merely *Suggests*
    // bzip2, so a lean image extracts nothing and reports a missing `lbzip2`,
    // a program named nowhere in this codebase. `unzip` is already required.
    expect($extract[0])->toBe('unzip');

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];
    expect(end($curl))->toEndWith('.zip');
});

it('copies out of the wrapping directory the zip ships', function () {
    $runs = installNextcloud();

    $copy = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'cp')['command'];

    // The zip's entries start at `nextcloud/`, unlike Mautic's flat one, and
    // `unzip` has no `--strip-components` to drop it. Copying from the
    // extract root instead would put the whole application one directory
    // below the web root: every URL a 404, and index.php nowhere.
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
    // 🔴 `--database` used to come from config alone — harmless while every
    // Nextcloud got MySQL, and a broken site the moment PostgreSQL is
    // accepted: occ would be handed `mysql` for a PostgreSQL database and die
    // inside its own install, past the point where acceptedEngines() could
    // have refused anything.
    $install = occRun(installNextcloud('postgresql'), 'maintenance:install');

    // `pgsql` is the value occ's own Install command accepts.
    expect($install['command'])->toContain('pgsql')
        ->not->toContain('mysql');
});

it('passes the PostgreSQL port, which occ does have an option for', function () {
    $command = occRun(installNextcloud('postgresql'), 'maintenance:install')['command'];
    $at = array_search('--database-port', $command, true);

    expect($at)->not->toBeFalse()
        // Off the engine's connection record, not a literal.
        ->and($command[$at + 1])->toBe('5432');
});

it('leaves the MySQL command line exactly as it was', function () {
    // Both halves of "don't touch the MySQL path" (operator, 2026-09-11): the
    // driver still comes from config, and no port option appears where none
    // appeared before. occ *has* `--database-port` and we have never passed
    // it — a real gap, filed as its own task, because closing it changes the
    // install of every existing Nextcloud.
    $command = occRun(installNextcloud(), 'maintenance:install')['command'];

    expect($command)->toContain('mysql')
        ->not->toContain('pgsql')
        ->not->toContain('--database-port');
});
