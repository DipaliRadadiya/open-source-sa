<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
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

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Books',
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

it('answers both prompts on stdin, database password first', function () {
    $runs = installAkaunting();
    $install = akauntingRun($runs);

    // The command asks for the database password (5th missing value) before
    // the admin one (9th). Reversed, the install succeeds with the two
    // swapped and nobody can log in.
    $lines = explode("\n", $install['input']);
    expect($lines[1])->toBe('AkauntPass1!')
        ->and($lines[0])->not->toBe('AkauntPass1!')
        ->and($lines[0])->not->toBe('');
});

it('keeps both passwords off the command line', function () {
    $runs = installAkaunting();

    foreach ($runs as $run) {
        $line = implode(' ', $run['command']);
        expect($line)->not->toContain('AkauntPass1!')
            ->and($line)->not->toContain('--db-password')
            ->and($line)->not->toContain('--admin-password');
    }
});

it('never passes --no-interaction, which would turn a question into an error', function () {
    $install = akauntingRun(installAkaunting());

    // With it, a missing option is a hard failure rather than a prompt — and
    // the prompts are the only way to get a secret in without argv.
    expect($install['command'])->not->toContain('--no-interaction');
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

    expect($install['path'])->toBe("{$this->home}/books.example.com")
        ->and(array_slice($install['command'], 0, 4))->toBe(['runuser', '-u', 'akuser', '--']);
});
