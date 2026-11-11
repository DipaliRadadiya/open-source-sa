<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\CloudflareRealIp;
use App\Services\Server\WebServers\NginxCompression;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/*
 * v7 vs v8, 2026-11-11 (Dipali: every stack the same as v7). Three things v7's
 * servers do that v8's did not: use the visitor's address from Cloudflare,
 * compress CSS/JS/JSON on nginx, and keep `www` in Apache's HTTP → HTTPS
 * redirect. Compared on the real v7 and v8 servers' files.
 */

function realIpServer(string $webServer): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => ['nginx' => 'lemp', 'apache' => 'lamp', 'openlitespeed' => 'ols'][$webServer],
        'web_server' => $webServer,
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);
    app()->forgetInstance(WebServerManager::class);
}

/**
 * A disk the fake commands read and write, so a second run sees what the
 * first one wrote.
 *
 * @param  array<string, string>  $files
 */
function realIpDisk(array $files = [], bool $testPasses = true, bool $stopFails = false): ArrayObject
{
    $state = new ArrayObject(['files' => $files, 'links' => [], 'reloads' => 0, 'tests' => 0, 'ran' => []]);

    Process::fake(function ($process) use ($state, $testPasses, $stopFails) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $files = $state['files'];
        $links = $state['links'];
        $state['ran'] = [...$state['ran'], implode(' ', $args)];
        $isTest = in_array($args[0] ?? '', ['nginx', 'apache2ctl', 'apachectl', '/usr/local/lsws/bin/openlitespeed'], true)
            && ($args[1] ?? '') !== '-v';

        $result = match (true) {
            $stopFails && $args === ['systemctl', 'stop', 'lshttpd'] => Process::result(exitCode: 1, errorOutput: 'refused'),
            $isTest => (function () use ($state, $testPasses) {
                $state['tests']++;

                return Process::result(exitCode: $testPasses ? 0 : 2, errorOutput: $testPasses ? '' : 'refused');
            })(),
            ($args[0] ?? '') === 'cat' => isset($files[$args[1]])
                ? Process::result(output: $files[$args[1]])
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory'),
            ($args[0] ?? '') === 'find' => Process::result(output: implode("\n", array_filter(
                array_keys($files + $links),
                fn (string $path) => dirname($path) === $args[1] && str_ends_with($path, '.conf'),
            ))),
            ($args[0] ?? '') === 'tee' => (function () use (&$files, $args, $process) {
                $files[$args[1]] = (string) $process->input;

                return Process::result();
            })(),
            ($args[0] ?? '') === 'cp' => (function () use (&$files, $args) {
                $files[$args[3]] = $files[$args[2]] ?? '';

                return Process::result();
            })(),
            ($args[0] ?? '') === 'ln' => (function () use (&$links, &$files, $args) {
                $links[$args[3]] = $args[2];

                return Process::result();
            })(),
            ($args[0] ?? '') === 'rm' => (function () use (&$files, &$links, $args) {
                $path = end($args);
                unset($files[$path], $links[$path]);

                return Process::result();
            })(),
            ($args[0] ?? '') === 'test' => Process::result(exitCode: match ($args[1] ?? '') {
                '-L' => isset($links[$args[2]]) ? 0 : 1,
                default => 0,
            }),
            in_array($args[0] ?? '', ['systemctl', '/usr/local/lsws/bin/lswsctrl'], true) => (function () use ($state) {
                $state['reloads']++;

                return Process::result();
            })(),
            default => Process::result(),
        };

        // A link reads as the file it points at.
        foreach ($links as $link => $target) {
            if (isset($files[$target])) {
                $files[$link] = $files[$target];
            } else {
                unset($files[$link]);
            }
        }

        $state['files'] = $files;
        $state['links'] = $links;

        return $result;
    });

    return $state;
}

/** Ubuntu's nginx.conf gzip section, as the package ships it. */
const STOCK_NGINX_CONF = "http {\n\tgzip on;\n\n\t# gzip_vary on;\n\t# gzip_proxied any;\n\t# gzip_comp_level 6;\n\t# gzip_buffers 16 8k;\n\t# gzip_http_version 1.1;\n\t# gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;\n\n\tinclude /etc/nginx/conf.d/*.conf;\n}\n";
/** The same section on a real v7 server: five lines switched on, gzip_vary still off. */
const V7_NGINX_CONF = "http {\n\tgzip on;\n\n\t# gzip_vary on;\n\tgzip_proxied any;\n\tgzip_comp_level 6;\n\tgzip_buffers 16 8k;\n\tgzip_http_version 1.1;\n\tgzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;\n\n\tinclude /etc/nginx/conf.d/*.conf;\n}\n";
const OLS_CONF = "serverName\n\naccessControl{\n\tallow                                   ALL\n\tdeny\n}\n \nextProcessor lsphp{\n}\n";
const V7_NGINX_REMOTEIP = "real_ip_header CF-Connecting-IP;\n\nset_real_ip_from  173.245.48.0/20;\n";

describe('Cloudflare visitor address', function () {
    it('trusts Cloudflare\'s IPv4 and IPv6 ranges on nginx in v7\'s file, once', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('updated');

        $config = $disk['files']['/etc/nginx/conf.d/remoteip.conf'];

        expect($config)->toStartWith('# Managed by the panel')
            ->toContain('real_ip_header CF-Connecting-IP;', 'set_real_ip_from 173.245.48.0/20;', 'set_real_ip_from 2606:4700::/32;')
            ->and($disk['reloads'])->toBe(1);

        // Nothing to do the second time, so nothing reloaded.
        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('current')
            ->and($disk['reloads'])->toBe(1);
    });

    it('leaves v7\'s own remoteip.conf as it is', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF, '/etc/nginx/conf.d/remoteip.conf' => V7_NGINX_REMOTEIP]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files']['/etc/nginx/conf.d/remoteip.conf'])->toBe(V7_NGINX_REMOTEIP)
            ->and($disk['reloads'])->toBe(0);
    });

    it('leaves a server whose owner set the header in another file', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF, '/etc/nginx/conf.d/my-proxy.conf' => "real_ip_header X-Real-IP;\n"]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files'])->not->toHaveKey('/etc/nginx/conf.d/remoteip.conf');
    });

    it('moves the panel\'s earlier panel-real-ip.conf to v7\'s name in one change', function () {
        // Both at once would be two real_ip_header lines, which nginx refuses.
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF, '/etc/nginx/conf.d/panel-real-ip.conf' => "# Managed by the panel\nreal_ip_header CF-Connecting-IP;\n"]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('updated')
            ->and($disk['files'])->toHaveKey('/etc/nginx/conf.d/remoteip.conf')
            ->and($disk['files'])->not->toHaveKey('/etc/nginx/conf.d/panel-real-ip.conf')
            ->and($disk['tests'])->toBe(1);
    });

    it('takes the file away again when nginx refuses it', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF], testPasses: false);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('failed')
            ->and($disk['files'])->not->toHaveKey('/etc/nginx/conf.d/remoteip.conf')
            ->and($disk['reloads'])->toBe(0);
    });

    it('trusts Cloudflare on Apache in v7\'s conf-enabled/remoteip.conf, inert without the module', function () {
        realIpServer('apache');
        $disk = realIpDisk([
            '/etc/apache2/conf-available/panel-real-ip.conf' => "# Managed by the panel\n",
        ]);
        $disk['links'] = ['/etc/apache2/conf-enabled/panel-real-ip.conf' => '/etc/apache2/conf-available/panel-real-ip.conf'];

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('updated');

        $config = $disk['files']['/etc/apache2/conf-enabled/remoteip.conf'];

        expect($config)->toContain('<IfModule remoteip_module>', 'RemoteIPHeader CF-Connecting-IP', 'RemoteIPTrustedProxy 104.16.0.0/13', 'RemoteIPTrustedProxy 2400:cb00::/32')
            // The panel's earlier copy is gone, file and link.
            ->and($disk['files'])->not->toHaveKey('/etc/apache2/conf-available/panel-real-ip.conf')
            ->and($disk['links'])->not->toHaveKey('/etc/apache2/conf-enabled/panel-real-ip.conf');
    });

    it('leaves v7\'s Apache remoteip.conf as it is', function () {
        realIpServer('apache');
        $v7 = "RemoteIPHeader CF-Connecting-IP\n\nRemoteIPTrustedProxy 173.245.48.0/20\n";
        $disk = realIpDisk(['/etc/apache2/conf-enabled/remoteip.conf' => $v7]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files']['/etc/apache2/conf-enabled/remoteip.conf'])->toBe($v7);
    });

    it('uses the header on OpenLiteSpeed from Cloudflare only, never from anyone', function () {
        realIpServer('openlitespeed');
        $disk = realIpDisk(['/usr/local/lsws/conf/httpd_config.conf' => OLS_CONF]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('updated');

        $config = $disk['files']['/usr/local/lsws/conf/httpd_config.conf'];

        // 2 is "Trusted IP Only"; 1 (v7's) would trust the header from any visitor.
        expect($config)->toMatch('/^useIpInProxyHeader\s+2$/m')
            ->and($config)->toMatch('/^\s*allow\s+ALL, 173\.245\.48\.0\/20T, .*2c0f:f248::\/32T$/m')
            ->and(substr_count($config, 'accessControl{'))->toBe(1)
            // Last, where v7 has it: OpenLiteSpeed ignored it above accessControl.
            ->and(trim(collect(explode("\n", trim($config)))->last()))->toBe('useIpInProxyHeader       2')
            ->and($disk['files'])->not->toHaveKey('/usr/local/lsws/conf/httpd_config.conf.panel-bak');

        // Cold: a graceful restart kept the old trusted list.
        expect($disk['ran'])->toContain('systemctl stop lshttpd', 'systemctl start lshttpd')
            ->and(collect($disk['ran'])->contains(fn (string $c) => str_contains($c, 'lswsctrl')))->toBeFalse();

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('current')
            ->and($disk['files']['/usr/local/lsws/conf/httpd_config.conf'])->toBe($config);
    });

    it('leaves OpenLiteSpeed alone where the owner chose the mode, or the allowed list is closed', function (string $conf) {
        realIpServer('openlitespeed');
        $disk = realIpDisk(['/usr/local/lsws/conf/httpd_config.conf' => $conf]);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files']['/usr/local/lsws/conf/httpd_config.conf'])->toBe($conf);
    })->with([
        'v7 sets 1' => [OLS_CONF."useIpInProxyHeader          1\n"],
        'allowed list is not ALL' => [str_replace('ALL', '192.0.2.0/24', OLS_CONF)],
    ]);

    it('says so when OpenLiteSpeed cannot be restarted cold, and keeps it serving', function () {
        realIpServer('openlitespeed');
        $disk = realIpDisk(['/usr/local/lsws/conf/httpd_config.conf' => OLS_CONF], stopFails: true);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('restart_needed')
            ->and($disk['ran'])->toContain('/usr/local/lsws/bin/lswsctrl restart')
            ->and($disk['ran'])->not->toContain('systemctl start lshttpd');
    });

    it('puts OpenLiteSpeed\'s config back when the test fails', function () {
        realIpServer('openlitespeed');
        $disk = realIpDisk(['/usr/local/lsws/conf/httpd_config.conf' => OLS_CONF], testPasses: false);

        expect(app(CloudflareRealIp::class)->ensure()['status'])->toBe('failed')
            ->and($disk['files']['/usr/local/lsws/conf/httpd_config.conf'])->toBe(OLS_CONF)
            ->and($disk['reloads'])->toBe(0);
    });
});

describe('nginx compression', function () {
    it('switches on Ubuntu\'s own gzip lines in nginx.conf, exactly as v7 has them', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF]);

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('updated')
            ->and($disk['files']['/etc/nginx/nginx.conf'])->toBe(V7_NGINX_CONF)
            ->and($disk['files'])->not->toHaveKey('/etc/nginx/conf.d/panel-gzip.conf');

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('current');
    });

    it('changes nothing on a v7 server', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => V7_NGINX_CONF]);

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('current')
            ->and($disk['files']['/etc/nginx/nginx.conf'])->toBe(V7_NGINX_CONF)
            ->and($disk['reloads'])->toBe(0);
    });

    it('moves the panel\'s earlier panel-gzip.conf into nginx.conf in one change', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF, '/etc/nginx/conf.d/panel-gzip.conf' => "# Managed by the panel\ngzip_vary on;\ngzip_comp_level 6;\n"]);

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('updated')
            ->and($disk['files']['/etc/nginx/nginx.conf'])->toBe(V7_NGINX_CONF)
            ->and($disk['files'])->not->toHaveKey('/etc/nginx/conf.d/panel-gzip.conf')
            ->and($disk['tests'])->toBe(1);
    });

    it('adds a missing line under gzip on, and leaves one the owner set', function () {
        realIpServer('nginx');
        $conf = str_replace(["\t# gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;\n", "\t# gzip_comp_level 6;\n"], ['', "\tgzip_comp_level 2;\n"], STOCK_NGINX_CONF);
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => $conf]);

        app(NginxCompression::class)->ensure();

        expect($disk['files']['/etc/nginx/nginx.conf'])->toContain("\tgzip on;\n\tgzip_types text/plain text/css", "\tgzip_comp_level 2;\n")
            ->not->toContain('gzip_comp_level 6');
    });

    it('leaves gzip off alone', function () {
        realIpServer('nginx');
        $conf = str_replace('gzip on;', 'gzip off;', STOCK_NGINX_CONF);
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => $conf]);

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files']['/etc/nginx/nginx.conf'])->toBe($conf);
    });

    it('puts nginx.conf back when nginx refuses the change', function () {
        realIpServer('nginx');
        $disk = realIpDisk(['/etc/nginx/nginx.conf' => STOCK_NGINX_CONF], testPasses: false);

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('failed')
            ->and($disk['files']['/etc/nginx/nginx.conf'])->toBe(STOCK_NGINX_CONF);
    });

    it('does nothing on Apache or OpenLiteSpeed, which already compress', function (string $webServer) {
        realIpServer($webServer);
        $disk = realIpDisk();

        expect(app(NginxCompression::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files'])->toBe([]);
    })->with(['apache', 'openlitespeed']);
});

it('keeps the name the visitor typed in Apache\'s redirect to HTTPS', function (string $profile, string $type) {
    realIpServer('apache');
    Process::fake();
    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    $application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => $type, 'serving_profile' => $profile, 'status' => 'active', 'web_root' => '/',
        'php_version' => '8.4', 'app_port' => $profile === 'node' ? 3000 : null,
    ]);
    $application->domains()->create(['domain' => 'blog.example.com', 'type' => DomainType::Primary]);
    $application->domains()->create(['domain' => 'old.example.com', 'type' => DomainType::Alias]);
    Certificate::create([
        'application_id' => $application->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
        'domains' => ['blog.example.com', 'www.blog.example.com'], 'issued_at' => now(), 'expires_at' => now()->addDays(89),
        'certificate_path' => '/etc/letsencrypt/live/blog/fullchain.pem', 'private_key_path' => '/etc/letsencrypt/live/blog/privkey.pem',
        'force_https' => true,
    ]);

    $config = app(ApacheDriver::class)->renderConfig($application->fresh(['domains', 'certificate', 'systemUser']), '/home/siteowner/blog/public_html');
    $http = substr($config, 0, strpos($config, '</VirtualHost>'));

    expect($http)->toContain('RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]')
        ->and($http)->toContain('RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/')
        ->and($http)->not->toContain('RedirectMatch 301')
        // A name the certificate does not cover still goes to the primary, first.
        ->and($http)->toContain('RewriteCond %{HTTP_HOST} ^old\.example\.com(:80)?$ [NC]')
        ->and(strpos($http, 'https://blog.example.com%{REQUEST_URI}'))->toBeLessThan(strpos($http, 'https://%{SERVER_NAME}'))
        ->and($http)->not->toContain('^www\.blog\.example\.com');
})->with([['php', 'wordpress'], ['static', 'static'], ['node', 'node']]);
