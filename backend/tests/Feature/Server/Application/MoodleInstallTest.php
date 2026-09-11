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
 */
function installMoodle(?string $engine = null): ArrayObject
{
    $runs = new ArrayObject;

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

    $config = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'config.php'));

    // Moodle's own installers take database credentials as arguments. Writing
    // config.php ourselves is what keeps them off the command line, where
    // `ps` would show them to every user on the machine.
    expect($config)->not->toBeNull()
        ->and($config['input'])->toContain('$CFG->dbpass');

    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('$CFG->dbpass');
    }
});

it('writes a config that is actually PHP', function () {
    $runs = installMoodle();

    $config = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'config.php'))['input'];

    expect($config)->toStartWith('<?php');

    $path = tempnam(sys_get_temp_dir(), 'mdl').'.php';
    file_put_contents($path, $config);
    exec('php -l '.escapeshellarg($path).' 2>&1', $out, $status);
    expect($status)->toBe(0);
    @unlink($path);
});

it('never puts the user\'s password on a command line', function () {
    $runs = installMoodle();

    // install_database.php has no prompt and refuses to run without
    // --adminpass, so it gets a throwaway; the real one arrives on stdin.
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

    // Random, belonging to nobody, and invalid moments later — so its brief
    // appearance in `ps` discloses nothing.
    expect($adminpass)->not->toContain('MoodlePass1!')
        ->and(strlen((string) $adminpass))->toBeGreaterThan(24);

    // And the replacement must come after, or the site is left on it.
    $order = collect($runs)->pluck('command')->map(fn ($c) => implode(' ', $c));
    expect($order->search(fn ($c) => str_contains($c, 'reset_password.php')))
        ->toBeGreaterThan($order->search(fn ($c) => str_contains($c, 'install_database.php')));
});

it('gives Moodle a data directory outside the web root', function () {
    $runs = installMoodle();

    // Beside public_html inside the site, not next to the site in the user's
    // home — `{home}/{slug}/moodledata`. Outside the web root, still the
    // site's own, which is what document roots becoming slug-based settled.
    $dataDir = "{$this->home}/courses/moodledata";
    $config = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'config.php'))['input'];

    // It holds every file every student uploads; inside the document root
    // each one is a URL.
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
    // Moodle's environment check reports this one as "this test must pass" —
    // unlike the database-version line beside it, which is advisory — and the
    // CLI default is 1000. Without it the install dies at the last step, on a
    // site whose files and database are already created.
    $runs = installMoodle();

    $install = collect($runs)->first(
        fn ($run) => in_array('admin/cli/install_database.php', $run['command'], true),
    );

    $flag = array_search('-d', $install['command'], true);

    expect($flag)->not->toBeFalse()
        ->and($install['command'][$flag + 1])->toBe('max_input_vars=5000')
        // Before the script, or PHP reads it as one of the script's own
        // arguments and applies nothing.
        ->and($flag)->toBeLessThan(array_search('admin/cli/install_database.php', $install['command'], true));
});

/** The config.php Moodle will read, as written. */
function moodleConfig(ArrayObject $runs): string
{
    return collect($runs)
        ->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'config.php'))['input'];
}

it('writes PostgreSQL\'s driver, and a config that is still valid PHP', function () {
    $config = moodleConfig(installMoodle('postgresql'));

    // `pgsql` per config-dist.php's own list. Moodle distinguishes engines
    // where most applications don't, so there is no value that covers both.
    expect($config)->toContain("\$CFG->dbtype    = 'pgsql'")
        ->not->toContain('mysqli');

    // The collation branch is inside a Blade @if in a generated PHP file —
    // the one place a stray directive would produce a config.php that parses
    // nowhere but looks fine in a diff.
    $path = tempnam(sys_get_temp_dir(), 'mdlpg').'.php';
    file_put_contents($path, $config);
    exec('php -l '.escapeshellarg($path).' 2>&1', $out, $status);
    expect($status)->toBe(0);
    @unlink($path);
});

it('drops dbcollation for PostgreSQL, which upstream says to remove', function () {
    // config-dist.php:66 says the option "should be removed for all other
    // databases". Left in, a PostgreSQL connection is handed
    // `utf8mb4_unicode_ci` — a collation that does not exist there.
    expect(moodleConfig(installMoodle('postgresql')))->not->toContain('dbcollation');
});

it('keeps dbcollation on MySQL, where it is the reason the option is there', function () {
    // The half a new branch quietly breaks: every Moodle the panel has made
    // is on MySQL and needs this line.
    expect(moodleConfig(installMoodle()))->toContain("'dbcollation' => 'utf8mb4_unicode_ci'");
});

it('fills in the port for PostgreSQL, off the engine\'s own connection record', function () {
    // New ground, so it starts correct — 5432 comes from the connection row,
    // not from a literal here.
    expect(moodleConfig(installMoodle('postgresql')))->toContain("'dbport' => '5432'");
});

it('leaves MySQL\'s port empty, as it has always been', function () {
    // A real gap and its own task (operator, 2026-09-11): filling it in here
    // would change the config every existing Moodle install path writes, to
    // fix a case nobody has reported. Pinned so the PostgreSQL branch cannot
    // leak into it.
    //
    // Separate test rather than one assertion per engine on purpose: the two
    // engines cannot share a `Process::fake`. With psql falling through to a
    // bare success, `available()` reads the engine as reachable while
    // `identifierAvailable()` reads its empty output as "name taken", so
    // allocation walks twenty candidates and the install dies at
    // create_database — which is what happened when these were one test.
    expect(moodleConfig(installMoodle()))->toContain("'dbport' => ''");
});
