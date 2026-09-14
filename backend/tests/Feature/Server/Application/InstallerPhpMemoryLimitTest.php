<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * An application's own CLI runs under a memory limit the panel sets, rather
 * than under whatever the interpreter's ini happens to say.
 *
 * Mautic on OpenLiteSpeed is the case that found this: "Allowed memory size of
 * 134217728 bytes exhausted" -- exactly 128M -- from Symfony's container
 * compile. The same command on nginx never failed, because Debian's CLI ini
 * carries `memory_limit = -1` while LiteSpeed's lsphp loads its production ini
 * at 128M. Nothing in the panel chose either number.
 *
 * Every PHP site type here runs a CLI step, so the guard that matters most is
 * the last one in this file: not that Mautic passes the flag, but that an
 * installer cannot reach the interpreter without it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-php-memory-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'memuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Marketing',
        'slug' => 'marketing',
        'domain' => 'mautic-memory.example.com',
        'site_type' => 'mautic',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'admin_user' => 'ada',
            'admin_email' => 'ada@example.com',
            'admin_password' => 'MauticPass1!',
            'mailer_email' => 'mailer@example.com',
            'mailer_host' => 'smtp.example.com',
            'mailer_port' => 587,
        ],
    ]);

    Http::fake(['api.github.com/*' => Http::response(['assets' => [
        ['browser_download_url' => 'https://github.com/mautic/mautic/releases/download/7.1.3/7.1.3.zip'],
    ]])]);
});

/**
 * @return ArrayObject<int, array<string, mixed>>
 */
function memoryLimitRuns(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command];

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

/**
 * The console command, as a flat list of arguments.
 *
 * `runAsSiteUser` wraps the command in `runuser`, so the interpreter is not at
 * position zero -- the assertion is about the flag sitting *before* the script,
 * which is the only place PHP reads `-d`.
 *
 * @param  ArrayObject<int, array<string, mixed>>  $runs
 * @return array<int, string>
 */
function mauticConsoleRun(ArrayObject $runs, string $command): array
{
    $run = collect($runs)->first(function (array $run) use ($command) {
        $argv = (array) $run['command'];

        return in_array('bin/console', $argv, true) && in_array($command, $argv, true);
    });

    expect($run)->not->toBeNull("no `bin/console {$command}` run was recorded");

    return array_values(array_map('strval', (array) $run['command']));
}

it('installs Mautic with an explicit memory limit', function () {
    $argv = mauticConsoleRun(memoryLimitRuns(), 'mautic:install');

    $flag = array_search('-d', $argv, true);

    expect($flag)->not->toBeFalse()
        ->and($argv[$flag + 1])->toBe('memory_limit=512M')
        // Before the script, or PHP treats it as an argument to Mautic.
        ->and($flag)->toBeLessThan(array_search('bin/console', $argv, true));
});

it('applies the limit to every CLI step, not only the install', function () {
    $runs = memoryLimitRuns();

    // The install is the step that ran out of memory; the verification query
    // right after it boots the same Symfony kernel and would fail identically.
    foreach (['mautic:install', 'doctrine:query:sql'] as $command) {
        expect(mauticConsoleRun($runs, $command))->toContain('memory_limit=512M');
    }
});

it('takes the limit from configuration', function () {
    config(['server.installer_php_memory_limit' => '768M']);

    expect(mauticConsoleRun(memoryLimitRuns(), 'mautic:install'))
        ->toContain('memory_limit=768M')
        ->not->toContain('memory_limit=512M');
});

it('leaves no installer able to reach the interpreter without a limit', function () {
    $dir = app_path('Services/Server/Applications/Installers');

    $offenders = collect(glob($dir.'/*.php'))
        ->filter(function (string $path) {
            $source = (string) file_get_contents($path);

            // `AbstractPhpInstaller` is the one place that may ask the PHP
            // stack for a path; every other installer has to go through it.
            // A Node installer asking the Node runtime is a different question
            // and not this rule's business.
            return basename($path) !== 'AbstractPhpInstaller.php'
                && (str_contains($source, 'phpBinary(') || str_contains($source, 'stack->binaryPath('));
        })
        ->map(fn (string $path) => basename($path))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('keeps the limit out of the site-type list', function () {
    // `ProvisioningBudget` reads the keys of `server.installers` as site types,
    // so a scalar added there would become a one-click application with no
    // driver. The key lives at the top level for that reason.
    expect(config('server.installer_php_memory_limit'))->toBe('512M')
        ->and(array_keys((array) config('server.installers')))
        ->not->toContain('installer_php_memory_limit')
        ->not->toContain('cli_memory_limit');
});
