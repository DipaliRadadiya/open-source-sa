<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\WebServers\CatchAllSite;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;

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

/*
 * Every branch of the site templates that listens: no certificate (the 443
 * reject), a certificate with and without forced HTTPS, both HTTP/2 syntaxes,
 * and a redirect domain the certificate covers (its own 443 and 80 blocks).
 * Without IPv6 the config is the IPv6 one minus its `[::]` lines, nothing else.
 */
function ipv6Variant(string $profile, string $tls): Application
{
    $site = ipv6Site($profile);
    $site->domains()->create(['domain' => "{$profile}.example.com", 'type' => DomainType::Primary]);
    $site->domains()->create(['domain' => "old-{$profile}.example.com", 'type' => DomainType::Redirect]);

    if ($tls !== 'none') {
        Certificate::create([
            'application_id' => $site->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
            'domains' => ["{$profile}.example.com", "old-{$profile}.example.com"],
            'certificate_path' => "/etc/letsencrypt/live/{$profile}/fullchain.pem",
            'private_key_path' => "/etc/letsencrypt/live/{$profile}/privkey.pem",
            'force_https' => $tls === 'forced',
            'issued_at' => now(), 'expires_at' => now()->addDays(89),
        ]);
    }

    return $site->fresh(['domains', 'certificate', 'systemUser']);
}

function nginxAt(string $version): void
{
    Process::fake(fn ($process) => array_slice($process->command, -2) === ['nginx', '-v']
        ? Process::result(errorOutput: "nginx version: nginx/{$version}")
        : Process::result());
}

it('renders the IPv6 config minus its [::] lines when the host has no IPv6', function (string $profile, string $tls, string $nginx) {
    nginxAt($nginx);
    $site = ipv6Variant($profile, $tls);
    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($site);

    $withIpv6 = app(NginxDriver::class)->renderConfig($site, $documentRoot);
    withoutIpv6();
    $withoutIpv6 = app(NginxDriver::class)->renderConfig($site, $documentRoot);

    $ipv6Lines = array_filter(explode("\n", $withIpv6), fn (string $line) => str_contains($line, '[::]'));
    $expected = implode("\n", array_filter(explode("\n", $withIpv6), fn (string $line) => ! str_contains($line, '[::]')));

    expect($ipv6Lines)->not->toBeEmpty()
        ->each->toMatch('/^\s*listen \[::\]:(80|443 ssl( http2)?);$/')
        ->and($withoutIpv6)->not->toContain('[::]')
        ->toBe($expected)
        // One IPv4 listen for every IPv6 one that went.
        ->and(preg_match_all('/^\s*listen (80|443 ssl)/m', $withoutIpv6))->toBe(count($ipv6Lines));
})->with(['php', 'static', 'node'])
    ->with(['none', 'optional', 'forced'])
    ->with(['http2 directive' => '1.28.0', 'listen http2' => '1.24.0']);

it('keeps both catch-all listens where the host has IPv6, and drops only [::] where it has not', function () {
    nginxAt('1.24.0');

    $withIpv6 = View::make('server.vhosts.nginx._catch-all', (fn () => $this->catchAllViewData())->call(app(NginxDriver::class)))->render();
    withoutIpv6();
    $withoutIpv6 = View::make('server.vhosts.nginx._catch-all', (fn () => $this->catchAllViewData())->call(app(NginxDriver::class)))->render();

    expect($withIpv6)->toContain('listen [::]:80 default_server;', 'listen [::]:443 ssl default_server;')
        ->and($withoutIpv6)->toBe(implode("\n", array_filter(
            explode("\n", $withIpv6),
            fn (string $line) => ! str_contains($line, '[::]'),
        )));
});

describe('hasIpv6', function () {
    it('is true when the kernel\'s IPv6 list exists and false when it does not', function () {
        $present = tempnam(sys_get_temp_dir(), 'if_inet6');

        try {
            config(['server.ipv6_probe_file' => $present]);
            expect(app(NginxDriver::class)->hasIpv6())->toBeTrue();

            withoutIpv6();
            expect(app(NginxDriver::class)->hasIpv6())->toBeFalse();
        } finally {
            @unlink($present);
        }
    });

    it('asks once per driver: the answer only changes with a reboot', function () {
        $present = tempnam(sys_get_temp_dir(), 'if_inet6');
        config(['server.ipv6_probe_file' => $present]);
        $driver = app(NginxDriver::class);

        expect($driver->hasIpv6())->toBeTrue();
        unlink($present);
        expect($driver->hasIpv6())->toBeTrue()
            ->and(app(NginxDriver::class)->hasIpv6())->toBeFalse();
    });
});
