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

function installMoodle(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return Process::result(exitCode: 0);
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

    $dataDir = "{$this->home}/moodledata";
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
