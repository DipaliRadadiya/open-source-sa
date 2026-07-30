<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
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

    $this->home = sys_get_temp_dir().'/sv-oss-joomla-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'jmluser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Site',
        'domain' => 'joomla.example.com',
        'site_type' => 'joomla',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'site_name' => 'My Joomla Site',
            'admin_name' => 'Jane Admin',
            'admin_user' => 'jadmin',
            'admin_email' => 'jane@example.com',
            'admin_password' => 'JoomlaPassw0rd!',
        ],
    ]);
});

function fakeJoomlaReleases(bool $ok = true): void
{
    Http::fake([
        'api.github.com/*' => $ok
            ? Http::response(['assets' => [
                ['browser_download_url' => 'https://github.com/joomla/joomla-cms/releases/download/6.1.2/Joomla_6.1.2-Stable-Update_Package.tar.gz'],
                ['browser_download_url' => 'https://github.com/joomla/joomla-cms/releases/download/6.1.2/Joomla_6.1.2-Stable-Full_Package.tar.gz'],
            ]])
            : Http::response('', 500),
    ]);
}

function installJoomla(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function joomlaInstallRun(ArrayObject $runs): array
{
    return collect($runs)->first(fn ($run) => in_array('installation/joomla.php', $run['command'], true));
}

it('unpacks without stripping, because the package has no wrapping directory', function () {
    fakeJoomlaReleases();
    $runs = installJoomla();

    $tar = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'tar')['command'];

    // Joomla's entries start at `administrator/`. Stripping a component would
    // discard the top-level directories and scatter their contents across the
    // web root.
    expect($tar)->not->toContain('--strip-components=1');
});

it('asks which release is current instead of holding a URL that will 404', function () {
    fakeJoomlaReleases();
    $runs = installJoomla();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    // The full package, not the update package — and resolved, because Joomla
    // publishes versioned filenames with no "latest" alias.
    expect(end($curl))->toBe('https://github.com/joomla/joomla-cms/releases/download/6.1.2/Joomla_6.1.2-Stable-Full_Package.tar.gz');
});

it('stops rather than unpacking something that is not Joomla', function () {
    fakeJoomlaReleases(ok: false);
    Process::fake();

    // A failed lookup must not fall through to downloading whatever answers.
    expect(fn () => app(ApplicationProvisioner::class)->provision($this->application))
        ->toThrow(ProvisioningFailedException::class);
});

it('never puts a password on the command line', function () {
    fakeJoomlaReleases();
    $runs = installJoomla();

    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('JoomlaPassw0rd!');
    }

    $install = joomlaInstallRun($runs);
    expect(collect($install['command'])->filter(fn ($a) => str_starts_with((string) $a, '--admin-password')))->toBeEmpty()
        ->and(collect($install['command'])->filter(fn ($a) => str_starts_with((string) $a, '--db-pass')))->toBeEmpty();
});

it('answers the prompts admin-first, which is the reverse of Nextcloud\'s order', function () {
    fakeJoomlaReleases();
    $runs = installJoomla();

    $lines = explode("\n", joomlaInstallRun($runs)['input']);

    // Joomla asks for the admin password (4th field) before the database one
    // (9th). Reversed, the install succeeds with the two swapped and the user
    // is locked out of a site that otherwise looks fine.
    expect($lines[0])->toBe('JoomlaPassw0rd!')
        ->and($lines[1])->not->toBe('JoomlaPassw0rd!')
        ->and($lines[1])->not->toBe('');
});

it('passes every non-secret option so Joomla has nothing else to ask about', function () {
    fakeJoomlaReleases();
    $command = joomlaInstallRun(installJoomla())['command'];

    // Anything omitted becomes a prompt, and a prompt with nothing to answer
    // it hangs the install until the timeout.
    expect($command)->toContain('--site-name=My Joomla Site')
        ->and($command)->toContain('--admin-user=Jane Admin')
        ->and($command)->toContain('--admin-username=jadmin')
        ->and($command)->toContain('--admin-email=jane@example.com')
        ->and($command)->toContain('--db-type=mysqli')
        ->and($command)->toContain('--db-encryption=0')
        ->and($command)->toContain('--public-folder=');
});

it('runs from the site directory, as the site user', function () {
    fakeJoomlaReleases();
    $install = joomlaInstallRun(installJoomla());

    expect($install['path'])->toBe("{$this->home}/joomla.example.com")
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'jmluser', '--']);
});

it('generates a table prefix when none is given', function () {
    fakeJoomlaReleases();
    $command = joomlaInstallRun(installJoomla())['command'];

    $prefix = collect($command)->first(fn ($a) => str_starts_with((string) $a, '--db-prefix='));

    // Joomla's own installer randomises this so tables stay apart if the
    // database is ever shared.
    expect($prefix)->toMatch('/^--db-prefix=[a-z0-9]{5}_$/');
});
