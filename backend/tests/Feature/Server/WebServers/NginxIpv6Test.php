<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\WebServers\CatchAllSite;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/*
 * A follow-up to installer review finding P1. The installer learned to leave
 * out its [::] listens on a host booted with ipv6.disable=1, but every nginx
 * site template the panel renders still had them. On such a host (live,
 * 2026-11-03) creating a static site failed at its config test with
 * "socket() [::]:80 failed (97: Address family not supported by protocol)",
 * and so did the catch-all, on [::]:443.
 */

beforeEach(function () {
    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

/** As on a host booted with ipv6.disable=1: the kernel's IPv6 list is not there. */
function withoutIpv6(): void
{
    config(['server.ipv6_probe_file' => sys_get_temp_dir().'/panel-test-no-if_inet6-'.bin2hex(random_bytes(4))]);
}

function ipv6Site(string $profile): Application
{
    $attributes = [
        'php' => ['site_type' => 'wordpress', 'serving_profile' => 'php', 'php_version' => '8.4'],
        'static' => ['site_type' => 'static', 'serving_profile' => 'static'],
        'node' => ['site_type' => 'git', 'serving_profile' => 'node', 'app_port' => 3300],
    ][$profile];

    return Application::forceCreate($attributes + [
        'system_user_id' => test()->systemUser->id,
        'name' => $profile, 'slug' => $profile, 'domain' => "{$profile}.example.com",
        'status' => 'active', 'web_root' => '/',
    ])->load('systemUser');
}

it('listens on IPv4 only when the host has no IPv6', function (string $profile) {
    withoutIpv6();

    $config = app(NginxDriver::class)->renderConfig(ipv6Site($profile), '/home/siteowner/site/public_html');

    expect($config)->not->toContain('[::]')
        ->toContain('listen 80;', 'listen 443 ssl;');
})->with(['php', 'static', 'node']);

it('still listens on IPv6 too where the host has it', function (string $profile) {
    $config = app(NginxDriver::class)->renderConfig(ipv6Site($profile), '/home/siteowner/site/public_html');

    expect($config)->toContain('listen 80;', 'listen [::]:80;', 'listen 443 ssl;', 'listen [::]:443 ssl;');
})->with(['php', 'static', 'node']);

it('reads the kernel\'s own IPv6 list by default, the file install.sh asks', function () {
    expect((string) file_get_contents(config_path('server.php')))
        ->toContain("'ipv6_probe_file' => env('SERVER_IPV6_PROBE_FILE', '/proc/net/if_inet6')")
        ->and(installerFn('have_ipv6'))->toContain('/proc/net/if_inet6');
});

it('leaves IPv6 out of the catch-all too', function () {
    withoutIpv6();
    $written = [];

    Process::fake(function ($process) use (&$written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'tee') {
            $written[(string) end($args)] = (string) $process->input;
        }

        return ($args[0] ?? '') === 'cat'
            ? Process::result(exitCode: 1, errorOutput: 'No such file or directory')
            : Process::result(output: 'nginx version: nginx/1.24.0');
    });

    expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated');

    $catchAll = collect($written)->first(fn (string $contents) => str_contains($contents, 'default_server'));

    expect($catchAll)->toContain('listen 80 default_server;', 'listen 443 ssl default_server;')
        ->not->toContain('[::]');
});

it('takes the IPv6 listens out of an existing site on sites:resync', function () {
    $site = ipv6Site('php');
    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($site);
    // What the site was written with before: the templates always listened on [::].
    $onDisk = app(WebServerManager::class)->driver()->renderConfig($site, $documentRoot);
    expect($onDisk)->toContain('listen [::]:80;');

    withoutIpv6();
    $written = [];

    Process::fake(function ($process) use ($onDisk, &$written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat') {
            return str_ends_with((string) end($args), '-ssl.conf')
                ? Process::result(exitCode: 1, errorOutput: 'No such file or directory')
                : Process::result(output: $onDisk);
        }
        if (($args[0] ?? '') === 'tee' && ! str_starts_with((string) end($args), '/etc/logrotate.d/')) {
            $written[] = (string) $process->input;
        }

        return Process::result();
    });

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['updated'])->toBe(1)
        ->and($result['failed'])->toBe([])
        ->and($written[0] ?? '')->toContain('listen 80;')
        ->not->toContain('[::]');
});
