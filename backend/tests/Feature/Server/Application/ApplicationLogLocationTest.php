<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;
use Illuminate\Support\Facades\Process;

/**
 * Every log a site produces, in one directory: `{appRoot}/logs`.
 *
 * They used to be in four places — the web server's under `/var/log/nginx` or
 * `/var/log/apache2`, the process logs already here, and the PHP error and WAF
 * detect logs inside `.panel`. So "where are my logs" depended on which web
 * server the box was built with and which kind of log was being asked about,
 * and two of the four were readable only by root.
 *
 * The ownership is the part worth guarding: root owns the directory, the site
 * user is the group. The owner of a site can read every log; nothing running
 * as that user can unlink one and put a symlink in its place for a root
 * process to append into.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->site = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);
});

it('puts every log for a site in one directory', function () {
    expect($this->site->logsPath())->toBe('/home/siteowner/shop/logs');
});

it('agrees across all three web servers', function () {
    // The whole point: the answer must not depend on which web server the
    // server happens to run. Before this, nginx and Apache answered
    // /var/log/... and only OpenLiteSpeed used the site's own directory.
    $expected = [
        'access' => '/home/siteowner/shop/logs/access.log',
        'error' => '/home/siteowner/shop/logs/error.log',
    ];

    expect(app(NginxDriver::class)->logPaths($this->site))->toBe($expected)
        ->and(app(ApacheDriver::class)->logPaths($this->site))->toBe($expected)
        ->and(app(OlsDriver::class)->logPaths($this->site))->toBe($expected);
});

it('keeps the PHP error log with the other logs, not inside .panel', function () {
    // `.panel` is the panel's own bookkeeping and is deliberately unreadable
    // by the site's owner — right for the Basic Auth credential, wrong for the
    // one file a developer whose site is throwing 500s needs most.
    $path = app(PoolManager::class)->errorLogPath($this->site);

    expect($path)->toBe('/home/siteowner/shop/logs/php-error.log')
        ->and($path)->not->toContain('.panel');
});

it('keeps the WAF detect log with the other logs, defined once', function () {
    // Two places used to build this string by hand and drifted: the file the
    // panel opened was not the file the web server wrote, so detect mode
    // showed an empty log however much it had matched.
    expect($this->site->wafDetectLogPath())->toBe('/home/siteowner/shop/logs/waf-detect.log')
        ->and($this->site->wafDetectLogPath())->not->toContain('.panel');
});

it('has the process supervisor and the model agree on the directory', function () {
    expect(ProcessSupervisor::logDir($this->site))->toBe($this->site->logsPath());
});

it('owns the directory as root with the site user as group', function () {
    // The security property. The site user must be able to *read* every log —
    // that is the reason they moved out of /var/log — and must not be able to
    // *unlink* one, because these files are opened by root: nginx's master,
    // systemd's PID 1, php-fpm's master. Directory write permission is
    // permission to unlink, so a user-owned log directory lets a compromised
    // site swap access.log for a symlink and have root append request text
    // into whatever it points at.
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $commands->push(implode(' ', (array) $process->command));

        return Process::result(exitCode: 0);
    });

    app(ApplicationLogDirectory::class)->ensure($this->site);

    $joined = $commands->join("\n");

    expect($joined)->toContain('mkdir -p /home/siteowner/shop/logs')
        ->and($joined)->toContain('chown root:siteowner /home/siteowner/shop/logs')
        ->and($joined)->toContain('chmod 0750 /home/siteowner/shop/logs')
        // Never the site user as owner — that is the whole distinction.
        ->and($joined)->not->toContain('chown siteowner:siteowner /home/siteowner/shop/logs');
});

it('creates the log directory before writing a vhost that names it', function () {
    // A web server refuses to start when a log file's directory is missing, so
    // this ordering is what stops `nginx -t` failing on every site and reading
    // as a bad template rather than an absent folder.
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $commands->push(implode(' ', (array) $process->command));

        return Process::result(exitCode: 0);
    });

    app(NginxDriver::class)->apply($this->site, $this->site->documentRoot());

    $mkdir = $commands->search(fn (string $c) => str_contains($c, 'mkdir -p /home/siteowner/shop/logs'));
    $write = $commands->search(fn (string $c) => str_contains($c, 'shop.conf'));

    expect($mkdir)->not->toBeFalse()
        ->and($write)->not->toBeFalse()
        ->and($mkdir)->toBeLessThan($write);
});

it('renders a vhost that logs where the driver says it does', function () {
    // The template and `logPaths()` are two separate statements of the same
    // fact. If they disagree, fail2ban and the Logs screen watch a file
    // nothing writes — which is exactly what a jail that never bans looks like.
    $config = app(NginxDriver::class)->renderConfig($this->site, $this->site->documentRoot());
    $paths = app(NginxDriver::class)->logPaths($this->site);

    expect($config)->toContain("access_log {$paths['access']};")
        ->and($config)->toContain("error_log  {$paths['error']};")
        ->and($config)->not->toContain('/var/log/nginx/shop');
});
