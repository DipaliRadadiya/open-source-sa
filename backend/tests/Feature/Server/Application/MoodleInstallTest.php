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

    $this->home = sys_get_temp_dir().'/sv-oss-moodle-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'mdluser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Courses',
        'slug' => 'courses',
        'domain' => 'learn.example.com',
        'site_type' => 'moodle',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'site_name' => 'My Courses',
            'short_name' => 'courses',
            'admin_user' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'MoodlePass1!',
        ],
    ]);
});

/**
 * @param  string|null  $engine  the engine the site asked for, on a server
 *                               that has only that one
 * @param  int|null  $port  a port the engine is NOT assumed to be on
 */
function installMoodle(?string $engine = null, ?int $port = null): ArrayObject
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
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return ($engine === 'postgresql' ? fakePostgresOnlyAnswer($process) : fakeDatabaseAnswer($process))
            ?? Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function moodleRun(ArrayObject $runs, string $script): ?array
{
    return collect($runs)->first(fn ($run) => in_array($script, $run['command'], true));
}

it('keeps the database password out of every command line', function () {
    $runs = installMoodle();

    $config = collect($runs)->first(fn ($run) => in_array('tee', $run['command'], true) && str_ends_with((string) end($run['command']), 'config.php'));

    // Moodle's installers take database credentials as arguments; writing config.php ourselves
    // keeps them off the command line, where `ps` shows them to every user.
    expect($config)->not->toBeNull()
        ->and($config['input'])->toContain('$CFG->dbpass');

    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('$CFG->dbpass');
    }
});

it('writes a config that is actually PHP', function () {
    $runs = installMoodle();

    $config = collect($runs)->first(fn ($run) => in_array('tee', $run['command'], true) && str_ends_with((string) end($run['command']), 'config.php'))['input'];

    expect($config)->toStartWith('<?php');

    $path = tempnam(sys_get_temp_dir(), 'mdl').'.php';
    file_put_contents($path, $config);
    exec('php -l '.escapeshellarg($path).' 2>&1', $out, $status);
    expect($status)->toBe(0);
    @unlink($path);
});

it('never puts the user\'s password on a command line', function () {
    $runs = installMoodle();

    // install_database.php refuses to run without --adminpass, so it gets a throwaway; the real
    // one arrives on stdin.
    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('MoodlePass1!');
    }

    $reset = moodleRun($runs, 'admin/cli/reset_password.php');
    expect($reset['input'])->toBe("MoodlePass1!\n");
});

it('installs with a throwaway password that is replaced immediately after', function () {
    $runs = installMoodle();

    $install = moodleRun($runs, 'admin/cli/install_database.php');
    $adminpass = collect($install['command'])->first(fn ($a) => str_starts_with((string) $a, '--adminpass='));

    // Random and invalid moments later, so its brief appearance in `ps` discloses nothing.
    expect($adminpass)->not->toContain('MoodlePass1!')
        ->and(strlen((string) $adminpass))->toBeGreaterThan(24);

    // The replacement must come after, or the site is left on the throwaway.
    $order = collect($runs)->pluck('command')->map(fn ($c) => implode(' ', $c));
    expect($order->search(fn ($c) => str_contains($c, 'reset_password.php')))
        ->toBeGreaterThan($order->search(fn ($c) => str_contains($c, 'install_database.php')));
});

it('gives Moodle a data directory outside the web root', function () {
    $runs = installMoodle();

    // `{home}/{slug}/moodledata`: beside public_html, outside the web root, still the site's own.
    $dataDir = "{$this->home}/courses/moodledata";
    $config = collect($runs)->first(fn ($run) => in_array('tee', $run['command'], true) && str_ends_with((string) end($run['command']), 'config.php'))['input'];

    // It holds every uploaded file; inside the document root each one would be a URL.
    expect($config)->toContain("\$CFG->dataroot  = '{$dataDir}'")
        ->and(collect($runs)->pluck('command'))->toContain(['chmod', '0750', $dataDir]);
});

it('runs both scripts from the site directory, as the site user', function () {
    $runs = installMoodle();

    foreach (['admin/cli/install_database.php', 'admin/cli/reset_password.php'] as $script) {
        $run = moodleRun($runs, $script);
        expect($run['path'])->toBe("{$this->home}/courses/public_html")
            ->and(array_slice($run['command'], 0, 4))->toBe(['runuser', '-u', 'mdluser', '--']);
    }
});

it('agrees to the licence, which Moodle refuses to install without', function () {
    $install = moodleRun(installMoodle(), 'admin/cli/install_database.php');

    expect($install['command'])->toContain('--agree-license');
});

it('raises max_input_vars on the interpreter, which Moodle refuses to install without', function () {
    // Moodle's environment check marks this "must pass" and the CLI default is 1000; without it
    // the install dies at the last step, after files and database are created.
    $runs = installMoodle();

    $install = collect($runs)->first(
        fn ($run) => in_array('admin/cli/install_database.php', $run['command'], true),
    );

    // Look for the value, not the first `-d`: the interpreter also carries the memory limit.
    $value = array_search('max_input_vars=5000', $install['command'], true);
    $flag = $value === false ? false : $value - 1;

    expect($flag)->not->toBeFalse()
        ->and($install['command'][$flag])->toBe('-d')
        // Before the script, or PHP reads it as a script argument and applies nothing.
        ->and($flag)->toBeLessThan(array_search('admin/cli/install_database.php', $install['command'], true));
});

/** The config.php Moodle will read, as written. */
function moodleConfig(ArrayObject $runs): string
{
    return collect($runs)
        ->first(fn ($run) => in_array('tee', $run['command'], true) && str_ends_with((string) end($run['command']), 'config.php'))['input'];
}

it('writes PostgreSQL\'s driver, and a config that is still valid PHP', function () {
    $config = moodleConfig(installMoodle('postgresql'));

    // `pgsql` per config-dist.php's own list; no single value covers both engines.
    expect($config)->toContain("\$CFG->dbtype    = 'pgsql'")
        ->not->toContain('mysqli');

    // The collation branch is a Blade @if inside generated PHP: a stray directive would give a
    // config.php that does not parse but looks fine in a diff.
    $path = tempnam(sys_get_temp_dir(), 'mdlpg').'.php';
    file_put_contents($path, $config);
    exec('php -l '.escapeshellarg($path).' 2>&1', $out, $status);
    expect($status)->toBe(0);
    @unlink($path);
});

it('drops dbcollation for PostgreSQL, which upstream says to remove', function () {
    // config-dist.php says the option "should be removed for all other databases"; left in,
    // PostgreSQL is handed `utf8mb4_unicode_ci`, a collation that does not exist there.
    expect(moodleConfig(installMoodle('postgresql')))->not->toContain('dbcollation');
});

it('keeps dbcollation on MySQL, where it is the reason the option is there', function () {
    // The half a new engine branch quietly breaks: MySQL needs this line.
    expect(moodleConfig(installMoodle()))->toContain("'dbcollation' => 'utf8mb4_unicode_ci'");
});

it('writes a moved MySQL port, which Moodle would otherwise assume is 3306', function () {
    // The panel's connection is editable: a managed MySQL on 25060 must not get a config saying
    // 3306, which provisions as Active but cannot reach its database.
    expect(moodleConfig(installMoodle(null, 25060)))->toContain("'dbport' => '25060'");
});

it('writes a moved PostgreSQL port too', function () {
    expect(moodleConfig(installMoodle('postgresql', 6432)))->toContain("'dbport' => '6432'");
});

it('leaves the port empty on a stock MySQL, as every existing Moodle has it', function () {
    // 3306 is what Moodle's driver assumes, so naming it would change every working config.
    //
    // A separate test per engine: the two cannot share a `Process::fake`. With psql falling
    // through to a bare success, `identifierAvailable()` reads empty output as "name taken" and
    // the install dies at create_database.
    expect(moodleConfig(installMoodle()))->toContain("'dbport' => ''");
});

it('leaves the port empty on a stock PostgreSQL as well', function () {
    // 5432 is PostgreSQL's own default; the same rule applies to every engine.
    expect(moodleConfig(installMoodle('postgresql')))->toContain("'dbport' => ''");
});

it('writes the port when only the PANEL\'s default has moved, not the application\'s', function () {
    // `default_port` is the panel's and env-overridable (SERVER_POSTGRES_PORT), while Moodle's
    // driver still assumes 5432: comparing against the panel's value would skip writing the port.
    config(['server.databases.engines.postgresql.default_port' => 5433]);

    expect(moodleConfig(installMoodle('postgresql')))->toContain("'dbport' => '5433'");
});

it('schedules Moodle\'s cron every minute, on the site\'s own PHP', function () {
    installMoodle();

    $job = Cronjob::query()->sole();

    expect($job->command)->toEndWith('/admin/cli/cron.php')
        ->and($job->command)->toStartWith('/usr/bin/php')
        ->and($job->expression)->toBe('* * * * *');
});
