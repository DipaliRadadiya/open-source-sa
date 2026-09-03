<?php

use App\Models\Application;
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

        return Process::result(exitCode: 0);
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

    // Every other installer here keeps secrets off argv, and so did this one:
    // the passwords were answered on stdin, on the reasoning that Akaunting's
    // `secret()` reached Symfony's question helper, which reads a pipe.
    //
    // Upstream has since moved to Laravel Prompts, which sees stdin is not a
    // TTY and returns the default without reading it. Because this installer
    // always fetches the LATEST release, that turned a correct assumption into
    // a silent failure with no code change on our side: the install ran with an
    // empty database password and died at "Could not connect to the database",
    // blaming credentials it had never been handed.
    //
    // So this is a deliberate exception rather than an oversight, and it is
    // asserted so nobody restores the stdin form by reading the rule and not
    // the reason. The exposure is real: `ps` shows these to every user on the
    // box for the life of the process.
    expect($install['command'])->toContain('--db-password='.Database::first()->users()->first()->password)
        ->and($install['command'])->toContain('--admin-password=AkauntPass1!')
        // Nothing is left on stdin to be read.
        ->and($install['input'])->toBe('');
});

it('passes --no-interaction, now that nothing is left to ask', function () {
    $install = akauntingRun(installAkaunting());

    // This used to be forbidden: with a value missing, `--no-interaction`
    // turns a question into a hard error, and the questions were how the
    // secrets got in. With every value supplied there is nothing to ask, and
    // it is the difference between a version that changes its prompting again
    // failing immediately and one that hangs until the timeout.
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
    // Akaunting is Laravel, so APP_URL is what it builds absolute links from
    // where there is no request to infer them — every emailed invoice link and
    // everything the queue generates. Left on the http:// address the site was
    // installed at, a shop behind a fresh certificate keeps mailing customers
    // links to an address they are not being asked to trust.
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
