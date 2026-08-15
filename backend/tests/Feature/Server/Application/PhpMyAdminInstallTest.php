<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-pma-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'pmauser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Database admin',
        'slug' => 'database-admin',
        'domain' => 'db.example.com',
        'site_type' => 'phpmyadmin',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
    ]);
});

/**
 * Capture every command and its stdin.
 */
function pmaRuns(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return Process::result(exitCode: 0);
    });

    return $runs;
}

function installPma(): ArrayObject
{
    $runs = pmaRuns();
    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

it('creates no database for a tool that reads the ones already there', function () {
    $runs = installPma();

    // phpMyAdmin authenticates as whoever logs in. A database of its own would
    // sit empty, and the account that came with it would be a standing
    // credential nobody asked for.
    expect(collect($runs)->pluck('command')->flatten())->not->toContain('CREATE DATABASE')
        ->and(Database::where('application_id', $this->application->id)->count())->toBe(0);
});

it('writes a config that is actually PHP', function () {
    $runs = installPma();

    $config = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'tee'
        && str_ends_with((string) ($run['command'][1] ?? ''), 'config.inc.php'));

    // A config file whose opening tag got escaped is served as plain text, so
    // the application never configures itself and the file's contents are
    // printed to whoever asks.
    expect($config)->not->toBeNull()
        ->and($config['input'])->toStartWith('<?php');

    $path = tempnam(sys_get_temp_dir(), 'pma').'.php';
    file_put_contents($path, $config['input']);

    $output = [];
    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);
    expect($status)->toBe(0);

    @unlink($path);
});

it('generates a different blowfish secret for every installation', function () {
    $first = installPma();
    $second = installPma();

    $secret = fn (ArrayObject $runs) => collect($runs)
        ->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'config.inc.php'))['input'];

    preg_match("/sodium_hex2bin\('([0-9a-f]+)'\)/", $secret($first), $a);
    preg_match("/sodium_hex2bin\('([0-9a-f]+)'\)/", $secret($second), $b);

    // This key encrypts the cookie carrying each user's database credentials.
    // A value shared between installations lets anyone holding it decrypt
    // sessions on all of them.
    expect($a[1])->toHaveLength(64)
        ->and($b[1])->toHaveLength(64)
        ->and($a[1])->not->toBe($b[1]);
});

it('asks each user for their own database credentials, storing none', function () {
    $runs = installPma();

    $config = collect($runs)->first(fn ($run) => str_ends_with((string) ($run['command'][1] ?? ''), 'config.inc.php'))['input'];

    // The alternative writes a database password into a file served from a
    // public web root, handing that account to every visitor.
    expect($config)->toContain("'auth_type'] = 'cookie'")
        ->and($config)->not->toContain("'password'")
        ->and($config)->toContain("'AllowNoPassword'] = false");
});

it('removes the setup wizard, which writes configuration over the web', function () {
    $runs = installPma();

    // Fine inside a distribution package, which secures it. On a tarball in a
    // public web root it is a configuration console on the open internet.
    expect(collect($runs)->pluck('command'))
        ->toContain(['rm', '-rf', "{$this->home}/database-admin/public_html/setup"]);
});

it('keeps the temp directory out of the web root', function () {
    $runs = installPma();

    $commands = collect($runs)->pluck('command');

    // phpMyAdmin writes uploads and exports there, and anything under the
    // document root is a URL somebody can fetch.
    // `{home}/{slug}/tmp`: outside the web root, inside the site.
    expect($commands)->toContain(['mkdir', '-p', "{$this->home}/database-admin/tmp"])
        ->and($commands)->toContain(['chmod', '0750', "{$this->home}/database-admin/tmp"]);
});

it('downloads over https only', function () {
    $runs = installPma();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    expect($curl)->toContain('--proto', '=https')
        ->and($curl)->toContain('--proto-redir', '=https')
        // Without --fail an HTML error page is unpacked as though it were the
        // application.
        ->and($curl)->toContain('--fail');
});
