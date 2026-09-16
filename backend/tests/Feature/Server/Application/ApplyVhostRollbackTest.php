<?php

use App\Actions\Server\Application\ApplyVhost;
use App\Enums\DomainType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/*
 * What a failed config test puts back.
 *
 * The rollback used to re-render the vhost from the database, which reads as
 * "restore the previous configuration" and is not that. The database already
 * holds the change being applied, so the render reproduced the *rejected*
 * config; and on a site whose file had been edited by hand, it reproduced
 * template output that had never been on disk. These tests pin the difference
 * by making the bytes on disk something no render could produce.
 */

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/sv-oss-rollback-'.getmypid();

    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.sites_available_dir' => $this->home.'/available',
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'rbuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Rollback',
        'slug' => 'rollback',
        'domain' => 'rollback.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    $this->application->domains()->create([
        'domain' => 'rollback.example.com',
        'type' => DomainType::Primary,
    ]);
});

/**
 * A server where `cat` answers with `$onDisk`, `nginx -t` passes or fails to
 * order, and every `tee` is recorded.
 *
 * Faked per command rather than with one blanket result: a single
 * `Process::result(exitCode: 0)` would make `cat` "succeed" with empty output,
 * which is the exact state this change exists to stop being mistaken for a
 * readable empty file.
 *
 * @param  string|null  $onDisk  contents `cat` returns, or null to make it fail
 * @param  list<array{path: string, input: string}>  $writes
 */
function fakeRollbackServer(?string $onDisk, bool $testPasses, array &$writes): void
{
    Process::fake(function ($process) use ($onDisk, $testPasses, &$writes) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat') {
            return $onDisk === null
                ? Process::result(exitCode: 1, errorOutput: 'cat: No such file or directory')
                : Process::result(output: $onDisk);
        }

        if (($args[0] ?? '') === 'tee') {
            $writes[] = ['path' => $args[1] ?? '', 'input' => $process->input ?? ''];
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

it('restores the bytes that were on disk, not a fresh render', function () {
    // Nothing the template can produce, so a re-render cannot pass this test
    // by coincidence.
    $handEdited = "# edited over ssh by the customer\nserver { listen 80; }\n";

    $writes = [];
    fakeRollbackServer(onDisk: $handEdited, testPasses: false, writes: $writes);

    expect(fn () => app(ApplyVhost::class)->execute($this->application))
        ->toThrow(ProvisioningFailedException::class);

    $configPath = app(WebServerManager::class)->driver()->configPath($this->application);
    $restores = array_values(array_filter($writes, fn ($w) => $w['path'] === $configPath));

    // Two writes to the vhost: the attempted apply, then the rollback.
    expect($restores)->toHaveCount(2)
        ->and(end($restores)['input'])->toBe($handEdited);
});

it('leaves the file alone when the previous contents could not be read', function () {
    $writes = [];
    fakeRollbackServer(onDisk: null, testPasses: false, writes: $writes);

    expect(fn () => app(ApplyVhost::class)->execute($this->application))
        ->toThrow(ProvisioningFailedException::class);

    $configPath = app(WebServerManager::class)->driver()->configPath($this->application);
    $restores = array_values(array_filter($writes, fn ($w) => $w['path'] === $configPath));

    // Only the apply. Writing an empty string here — because `cat` was refused
    // rather than because the file was empty — would blank a live vhost.
    expect($restores)->toHaveCount(1);
});

it('does not rewrite the vhost when the config test passes', function () {
    $writes = [];
    fakeRollbackServer(onDisk: "# whatever was there\n", testPasses: true, writes: $writes);

    app(ApplyVhost::class)->execute($this->application);

    $configPath = app(WebServerManager::class)->driver()->configPath($this->application);
    $restores = array_values(array_filter($writes, fn ($w) => $w['path'] === $configPath));

    expect($restores)->toHaveCount(1);
});
