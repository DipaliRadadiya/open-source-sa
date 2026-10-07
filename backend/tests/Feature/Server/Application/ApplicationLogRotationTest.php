<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationArtifacts;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Applications\ApplicationLogRotation;
use Illuminate\Support\Facades\Process;

/*
| LOG-01: Node sites had a logrotate policy that ran as the site user over
| files root writes, so it failed — and took the whole nightly logrotate run
| with it. Every other site had no policy, and its logs grew forever.
*/

beforeEach(function () {
    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    $this->site = Application::forceCreate([
        'system_user_id' => $this->systemUser->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $this->ran = new ArrayObject;
    $this->written = new ArrayObject;

    Process::fake(function ($process) {
        $command = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $this->ran->append($command);

        if (($command[0] ?? '') === 'tee') {
            $this->written[(string) end($command)] = (string) $process->input;
        }

        return Process::result();
    });
});

function onStack(string $stack, string $webServer): void
{
    ServerCapability::query()->delete();
    ServerCapability::create(['stack' => $stack, 'web_server' => $webServer, 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);
}

function policyFor(Application $site): string
{
    return (string) (test()->written['/etc/logrotate.d/'.$site->slug.'-webserver-logs'] ?? '');
}

it('gives every site a policy for its logs, run as root', function () {
    onStack('lemp', 'nginx');

    app(ApplicationLogDirectory::class)->ensure($this->site);

    $logs = $this->site->logsPath();
    $policy = policyFor($this->site);

    expect($policy)->toContain("{$logs}/access.log {$logs}/error.log {$logs}/app.log {$logs}/app-error.log")
        ->toContain('copytruncate')
        ->toContain('rotate 14')
        // The broken policy ran as the site user, who cannot open files root writes.
        ->not->toContain('su ')
        // Supervisor rotates its worker logs itself.
        ->not->toContain('*.log');
});

it('leaves the logs OpenLiteSpeed rotates itself alone', function () {
    onStack('ols', 'openlitespeed');

    $policy = app(ApplicationLogRotation::class)->render($this->site);
    $logs = $this->site->logsPath();

    expect($policy)->not->toContain("{$logs}/access.log")
        ->not->toContain("{$logs}/error.log ")
        ->toContain("{$logs}/app.log")
        ->toContain("{$logs}/php-error.log");
});

it('removes the old Node policy that failed the nightly run', function () {
    onStack('lemp', 'nginx');

    app(ApplicationLogDirectory::class)->ensure($this->site);

    $commands = collect($this->ran)->map(fn ($c) => implode(' ', $c))->values();
    $written = $commands->search(fn ($c) => $c === 'tee /etc/logrotate.d/shop-webserver-logs');

    expect($written)->not->toBeFalse();

    // Both names this policy had before go, after the current one is in place.
    foreach (['sv-app-', 'sv-site-'] as $old) {
        $removed = $commands->search(fn ($c) => $c === 'rm -f /etc/logrotate.d/'.$old.$this->site->id);

        expect($removed)->not->toBeFalse()->and($removed)->toBeGreaterThan($written);
    }
});

it('keeps the old policy when the new one could not be written', function () {
    onStack('lemp', 'nginx');
    Process::fake(fn ($process) => in_array('tee', $process->command, true)
        ? Process::result(exitCode: 1, errorOutput: 'No space left on device')
        : Process::result());

    app(ApplicationLogRotation::class)->write($this->site);

    foreach (['sv-app-', 'sv-site-'] as $old) {
        Process::assertNotRan(fn ($process) => in_array('/etc/logrotate.d/'.$old.$this->site->id, $process->command, true));
    }
});

it('removes the policy with the site', function () {
    onStack('lemp', 'nginx');

    app(ApplicationArtifacts::class)->remove($this->site);

    $commands = collect($this->ran)->map(fn ($c) => implode(' ', $c));

    expect($commands)->toContain('rm -f /etc/logrotate.d/shop-webserver-logs')
        ->toContain('rm -f /etc/logrotate.d/sv-site-'.$this->site->id)
        ->toContain('rm -f /etc/logrotate.d/sv-app-'.$this->site->id);
});

it('writes over the policy v7 left for the site, under the same name', function () {
    // v7 names it `{site}-webserver-logs` and globs `logs/*.log`. Two policies
    // naming one log fail logrotate's whole nightly run, so v8 uses v7's name
    // and the file is replaced, never joined by a second one.
    onStack('lemp', 'nginx');

    app(ApplicationLogRotation::class)->write($this->site);

    $tees = collect($this->ran)->filter(fn ($c) => ($c[0] ?? '') === 'tee' && str_starts_with((string) end($c), '/etc/logrotate.d/'))
        ->map(fn ($c) => end($c))->values()->all();

    expect($tees)->toBe(['/etc/logrotate.d/shop-webserver-logs']);
});
