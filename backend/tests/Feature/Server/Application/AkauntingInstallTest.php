<?php

use App\Models\Application;
use App\Models\Cronjob;
use App\Models\Database;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\Installers\AkauntingInstaller;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-ak-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'akuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Books',
        'slug' => 'books',
        'domain' => 'books.example.com',
        'site_type' => 'akaunting',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'company_name' => 'Acme Ltd',
            'company_email' => 'hello@acme.test',
            'admin_email' => 'admin@acme.test',
            'admin_password' => 'AkauntPass1!',
        ],
    ]);

    Http::fake(['api.github.com/*' => Http::response(['assets' => [
        ['browser_download_url' => 'https://github.com/akaunting/akaunting/releases/download/3.2.1/Akaunting_3.2.1-Stable.zip'],
    ]])]);
});

function installAkaunting(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function akauntingRun(ArrayObject $runs): array
{
    return collect($runs)->first(fn ($run) => in_array('install', $run['command'], true)
        && in_array('artisan', $run['command'], true));
}

it('passes both passwords as options, because this version will not read a pipe', function () {
    $install = akauntingRun(installAkaunting());

    // A deliberate exception to "secrets stay off argv": Akaunting now uses Laravel Prompts, which
    // sees stdin is not a TTY and returns the default without reading it, so passwords sent on
    // stdin were silently ignored (empty DB password, "Could not connect to the database").
    // The exposure is real: `ps` shows these to every user on the box while the process runs.
    expect($install['command'])->toContain('--db-password='.Database::first()->users()->first()->password)
        ->and($install['command'])->toContain('--admin-password=AkauntPass1!')
        // Nothing is left on stdin to be read.
        ->and($install['input'])->toBe('');
});

it('passes --no-interaction, now that nothing is left to ask', function () {
    $install = akauntingRun(installAkaunting());

    // With every value supplied there is nothing to ask, so a future prompting change fails
    // immediately instead of hanging until the timeout.
    expect($install['command'])->toContain('--no-interaction');
});

it('supplies every other option so nothing else is asked', function () {
    $command = akauntingRun(installAkaunting())['command'];

    expect($command)->toContain('--db-name='.Database::first()->name)
        ->and($command)->toContain('--company-name=Acme Ltd')
        ->and($command)->toContain('--company-email=hello@acme.test')
        ->and($command)->toContain('--admin-email=admin@acme.test')
        ->and($command)->toContain('--db-port=3306')
        ->and($command)->toContain('--locale=en-GB');
});

it('unzips the resolved release', function () {
    $runs = installAkaunting();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];
    $extract = collect($runs)->first(fn ($run) => in_array($run['command'][0] ?? '', ['unzip', 'tar'], true))['command'];

    expect(end($curl))->toContain('Akaunting_3.2.1-Stable.zip')
        // Flat archive — entries start at README.md — so nothing to strip.
        ->and($extract[0])->toBe('unzip');
});

it('runs from the site directory as the site user', function () {
    $install = akauntingRun(installAkaunting());

    expect($install['path'])->toBe("{$this->home}/books/public_html")
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'akuser', '--']);
});

it('follows the domain when a certificate is issued, and clears the cached config', function () {
    // APP_URL is what Akaunting builds absolute links from where there is no request (emailed
    // invoices, queued jobs). Left on the old http:// address, mails keep linking to it.
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return ($process->command[0] ?? '') === 'cat'
            ? Process::result(output: "APP_NAME=Akaunting\nAPP_URL=http://books.example.com\n")
            : Process::result(exitCode: 0);
    });

    app(AkauntingInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'https://books.example.com/');

    $written = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'tee');

    // Quoted, and without the trailing slash: a dotenv value ending in `/`
    // doubles every generated path, and an unquoted one ends at a `#`.
    expect($written['input'])->toContain('APP_URL="https://books.example.com"')
        // The rest of the file is the user's — not rebuilt from parsed pairs.
        ->and($written['input'])->toContain('APP_NAME=Akaunting');

    expect(collect($runs)->contains(fn ($run) => in_array('config:clear', $run['command'], true)))
        ->toBeTrue();
});

it('schedules Laravel\'s scheduler every minute, once', function () {
    installAkaunting();

    $job = Cronjob::query()->sole();

    expect($job->command)->toBe('/usr/bin/php8.4 -f '.$this->application->documentRoot().'/artisan schedule:run')
        ->and($job->expression)->toBe('* * * * *')
        ->and($job->application_owned)->toBeTrue();

    installAkaunting();

    expect(Cronjob::query()->count())->toBe(1);
});
