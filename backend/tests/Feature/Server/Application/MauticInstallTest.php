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
            'site_title' => "Growth & O'Reilly",
            'admin_first_name' => 'Ada',
            'admin_last_name' => "O'Reilly",
            'admin_user' => 'ada',
            'admin_email' => 'ada@example.com',
            'admin_password' => "Mautic&<Pass>'\"1!",
            'mailer_name' => "Ada & O'Reilly",
            'mailer_email' => 'mailer@example.com',
            'mailer_host' => 'smtp.example.com',
            'mailer_port' => 587,
            'mailer_username' => 'mailer-user',
            'mailer_password' => "SMTP&<Pass>'\"1!",
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

/** @return array<string, mixed> */
function mauticLocalParameters(ArrayObject $runs): array
{
    $path = tempnam(sys_get_temp_dir(), 'mtc');
    file_put_contents($path, mauticLocalConfig($runs));

    $parameters = [];
    include $path;
    @unlink($path);

    return $parameters;
}

it('runs the install with no secret on the command line at all', function () {
    $runs = installMautic();

    // Mautic merges local.php before command-line options, so credentials do
    // not have to be exposed to every local user through `ps`.
    foreach ($runs as $run) {
        $line = implode(' ', $run['command']);
        expect($line)->not->toContain("Mautic&<Pass>'\"1!")
            ->and($line)->not->toContain("SMTP&<Pass>'\"1!")
            ->and($line)->not->toContain('--db_password')
            ->and($line)->not->toContain('--admin_password');
    }
});

it('keeps the pre-install config incomplete and preserves special characters', function () {
    $runs = installMautic();
    $config = mauticLocalConfig($runs);
    $parameters = mauticLocalParameters($runs);

    expect($config)->toStartWith('<?php')
        // Mautic 7.1 treats db_driver + site_url as proof that installation
        // already finished. The URL belongs to the CLI argument until Mautic
        // writes it here in its own final step.
        ->and($config)->not->toContain("'site_url'");

    expect($parameters)->not->toHaveKey('site_url');
    expect($parameters['db_password'])->not->toBeEmpty()
        ->and($parameters['admin_password'])->toBe("Mautic&<Pass>'\"1!")
        ->and($parameters['admin_lastname'])->toBe("O'Reilly")
        ->and($parameters['site_title'])->toBe("Growth & O'Reilly")
        ->and($parameters['mailer_from_name'])->toBe("Ada & O'Reilly")
        ->and($parameters['mailer_password'])->toBe("SMTP&<Pass>'\"1!");
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

it('runs the installer non-interactively from the site directory as the site user', function () {
    $runs = installMautic();

    $install = collect($runs)->first(fn ($run) => in_array('mautic:install', $run['command'], true));

    expect($install['path'])->toBe("{$this->home}/marketing/public_html")
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'mtcuser', '--'])
        ->and($install['command'])->toContain('--force')
        ->and($install['command'])->toContain('--no-interaction');
});

it('verifies the installed schema before accepting a zero exit code', function () {
    $runs = installMautic();

    $verify = collect($runs)->first(fn ($run) => in_array('doctrine:query:sql', $run['command'], true));

    expect($verify)->not->toBeNull()
        ->and($verify['path'])->toBe("{$this->home}/marketing/public_html")
        ->and(array_slice($verify['command'], 0, 4))->toBe(['runuser', '-u', 'mtcuser', '--'])
        ->and($verify['command'])->toContain('SELECT COUNT(*) FROM users')
        ->and($verify['command'])->toContain('--no-interaction');
});

it('rejects Mautic already installed exit zero when the schema is empty', function () {
    Process::fake(function ($process) {
        if (in_array('mautic:install', $process->command, true)) {
            return Process::result(output: "Mautic already installed\n", exitCode: 0);
        }

        if (in_array('doctrine:query:sql', $process->command, true)) {
            return Process::result(errorOutput: "Table 'users' does not exist\n", exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    try {
        app(ApplicationProvisioner::class)->provision($this->application);
        $this->fail('An empty Mautic schema must not be accepted as an installed application.');
    } catch (ProvisioningFailedException $exception) {
        expect($exception->step)->toBe('verify_install');
    }
});
