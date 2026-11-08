<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\SiteConfFolder;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/*
 * v7 loads every `*.conf` in `{site}/conf/{web server}/` from the vhost: its
 * own bot blocker, 8G firewall and Apache force-HTTPS live there, and so do
 * users' own rules. v8 did not load it, so the first time v8 rewrote a site
 * moved from v7 all of that stopped working, silently (seen on real v7 and v8
 * servers, 2026-11-08). Dipali: vhosts the same as v7. The folder is loaded
 * where v7 loads it and is root's, so the rules keep working and the site user
 * can no longer write web server config that runs as root.
 */

beforeEach(function () {
    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

function confSite(string $profile = 'php', string $type = 'wordpress'): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => 'blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => $type, 'serving_profile' => $profile,
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        'app_port' => $profile === 'node' ? 3000 : null,
    ])->load('systemUser');
}

function useWebServer(string $name): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => ['nginx' => 'lemp', 'apache' => 'lamp', 'openlitespeed' => 'ols'][$name],
        'web_server' => $name, 'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);
    app()->forgetInstance(WebServerManager::class);
    // The box the test runs on has no /etc/apache2 or lsws; the record is the answer here.
    Process::fake();
}

it('loads the v7 folder in every nginx vhost, last in the site block', function (string $profile, string $type) {
    useWebServer('nginx');
    $config = app(NginxDriver::class)->renderConfig(confSite($profile, $type), '/home/siteowner/blog/public_html');

    expect($config)->toContain('include /home/siteowner/blog/conf/nginx/*.conf;');

    // After the PHP location, as in v7: its rules are server-level `if`s and
    // includes, and v7 users' regex locations were written against that order.
    if ($profile === 'php') {
        expect(strpos($config, 'conf/nginx/*.conf'))->toBeGreaterThan(strpos($config, 'fastcgi_pass'));
    }
})->with([['php', 'wordpress'], ['static', 'static'], ['node', 'node']]);

it('loads the v7 folder in every Apache vhost', function (string $profile, string $type) {
    useWebServer('apache');
    $config = app(ApacheDriver::class)->renderConfig(confSite($profile, $type), '/home/siteowner/blog/public_html');

    expect($config)->toContain('IncludeOptional /home/siteowner/blog/conf/apache/*');
})->with([['php', 'wordpress'], ['static', 'static'], ['node', 'node']]);

it('loads the v7 folder and its rewrite rules in every OpenLiteSpeed vhost', function (string $profile, string $type) {
    useWebServer('openlitespeed');
    $config = app(OlsDriver::class)->renderConfig(confSite($profile, $type), '/home/siteowner/blog/public_html');

    expect($config)->toContain('include /home/siteowner/blog/conf/openlitespeed/*.conf')
        ->and($config)->toContain('include /home/siteowner/blog/conf/openlitespeed/rewrites/*.conf');

    // The rewrite rules only work inside `rewrite {}`.
    $rewrite = substr($config, strpos($config, "rewrite {\n"));
    expect(strpos($rewrite, 'rewrites/*.conf'))->toBeLessThan(strpos($rewrite, "\n}"));
})->with([['php', 'wordpress'], ['static', 'static'], ['node', 'node']]);

it('makes the folder and what v7 left in it root\'s, without following links', function () {
    useWebServer('nginx');
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = implode(' ', $args);

        // The user planted a link where the folder should be.
        if ($args === ['test', '-L', '/home/siteowner/blog/conf/nginx']) {
            return Process::result();
        }

        return Process::result(exitCode: ($args[0] ?? '') === 'test' ? 1 : 0);
    });

    app(SiteConfFolder::class)->ensure(confSite(), 'nginx');

    expect($ran)->toContain('rm -f /home/siteowner/blog/conf/nginx')
        ->and($ran)->toContain('chown -h root:root /home/siteowner/blog/conf')
        ->and($ran)->toContain('chown -h root:root /home/siteowner/blog/conf/nginx')
        ->and($ran)->toContain('chown -R -h root:root /home/siteowner/blog/conf/nginx')
        ->and($ran)->toContain('chmod -R go-w /home/siteowner/blog/conf/nginx')
        ->and(collect($ran)->contains(fn ($c) => str_contains($c, 'chown -R') && ! str_contains($c, '-h')))->toBeFalse();

    // The link goes before anything is created or re-owned at that path.
    expect(array_search('rm -f /home/siteowner/blog/conf/nginx', $ran))
        ->toBeLessThan(array_search('mkdir -p /home/siteowner/blog/conf/nginx', $ran));
});

it('creates the rewrites folder OpenLiteSpeed reads', function () {
    useWebServer('openlitespeed');
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = implode(' ', $args);

        return Process::result(exitCode: ($args[0] ?? '') === 'test' ? 1 : 0);
    });

    app(SiteConfFolder::class)->ensure(confSite(), 'openlitespeed');

    expect($ran)->toContain('mkdir -p /home/siteowner/blog/conf/openlitespeed/rewrites');
});
