<?php

use App\Models\ServerCapability;
use App\Services\Server\WebServers\CatchAllSite;
use Illuminate\Support\Facades\Process;

/*
 * Bug #55: a domain no site claims was answered by the first site the web
 * server loaded — on the nginx and Apache test servers, a customer's
 * Akaunting site with its certificate — and on OpenLiteSpeed by the panel's
 * certificate. v7 has a neutral default; this is the panel's.
 */

function catchAllServer(string $webServer): void
{
    ServerCapability::create([
        'stack' => $webServer === 'apache' ? 'lamp' : 'lemp',
        'web_server' => $webServer,
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);
}

/**
 * A disk the fake commands read and write, so a second run sees what the
 * first one wrote.
 *
 * @param  array<string, string>  $files
 */
function catchAllDisk(array $files = [], string $nginx = '1.28.3', bool $testPasses = true): ArrayObject
{
    $state = new ArrayObject(['files' => $files, 'links' => [], 'reloads' => 0, 'tests' => 0]);

    Process::fake(function ($process) use ($state, $nginx, $testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $files = $state['files'];
        $links = $state['links'];

        $result = match ($args[0] ?? '') {
            'cat' => isset($files[$args[1]])
                ? Process::result(output: $files[$args[1]])
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory'),
            'tee' => (function () use (&$files, $args, $process) {
                $files[$args[1]] = (string) $process->input;

                return Process::result();
            })(),
            'cp' => (function () use (&$files, $args) {
                $files[$args[3]] = $files[$args[2]] ?? '';

                return Process::result();
            })(),
            'ln' => (function () use (&$links, $args) {
                $links[$args[3]] = $args[2];

                return Process::result();
            })(),
            'rm' => (function () use (&$files, &$links, $args) {
                unset($files[$args[2]], $links[$args[2]]);

                return Process::result();
            })(),
            'test' => Process::result(exitCode: match ($args[1] ?? '') {
                '-L' => isset($links[$args[2]]) ? 0 : 1,
                default => 0,
            }),
            'nginx' => ($args[1] ?? '') === '-v'
                ? Process::result(errorOutput: "nginx version: nginx/{$nginx} (Ubuntu)")
                : (function () use ($state, $testPasses) {
                    $state['tests']++;

                    return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'a duplicate default server');
                })(),
            'apache2ctl', 'apachectl' => (function () use ($state, $testPasses) {
                $state['tests']++;

                return Process::result(exitCode: $testPasses ? 0 : 1);
            })(),
            'systemctl', '/usr/local/lsws/bin/lswsctrl' => (function () use ($state) {
                $state['reloads']++;

                return Process::result();
            })(),
            default => Process::result(),
        };

        $state['files'] = $files;
        $state['links'] = $links;

        return $result;
    });

    return $state;
}

const NGINX_CATCH_ALL = '/etc/nginx/conf.d/panel-default-server.conf';
const APACHE_CATCH_ALL = '/etc/apache2/conf-available/panel-default-site.conf';
const APACHE_CATCH_ALL_LINK = '/etc/apache2/conf-enabled/panel-default-site.conf';

describe('nginx', function () {
    beforeEach(fn () => catchAllServer('nginx'));

    it('answers unknown names itself, refusing the TLS handshake', function () {
        $disk = catchAllDisk();

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated');

        $config = $disk['files'][NGINX_CATCH_ALL];

        expect($config)->toContain('listen 80 default_server;', 'listen [::]:443 ssl default_server;', 'ssl_reject_handshake on;', 'return 404;')
            ->and($config)->not->toContain('ssl_certificate ')
            ->and($disk['reloads'])->toBe(1);
    });

    it('uses the reserved certificate on an nginx too old to refuse a handshake', function () {
        $disk = catchAllDisk(nginx: '1.18.0');

        app(CatchAllSite::class)->ensure();

        expect($disk['files'][NGINX_CATCH_ALL])->not->toContain('ssl_reject_handshake')
            ->toContain('.panel-tls-reject.crt', 'return 444;');
    });

    it('writes and reloads nothing the second time', function () {
        $disk = catchAllDisk();
        app(CatchAllSite::class)->ensure();

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('current')
            ->and($disk['reloads'])->toBe(1);
    });

    it('takes itself back out when the web server refuses it, and never reloads', function () {
        // A server moved from v7 already has a default_server of its own.
        $disk = catchAllDisk(testPasses: false);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('failed')
            ->and($disk['files'])->not->toHaveKey(NGINX_CATCH_ALL)
            ->and($disk['reloads'])->toBe(0);
    });

    it('puts back the previous version when an update is refused', function () {
        $disk = catchAllDisk([NGINX_CATCH_ALL => 'what was there'], testPasses: false);

        app(CatchAllSite::class)->ensure();

        expect(trim($disk['files'][NGINX_CATCH_ALL]))->toBe('what was there');
    });
});

describe('Apache', function () {
    beforeEach(fn () => catchAllServer('apache'));

    it('loads before every site and denies, with the reserved certificate on 443', function () {
        $disk = catchAllDisk();

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated');

        expect($disk['links'][APACHE_CATCH_ALL_LINK] ?? null)->toBe(APACHE_CATCH_ALL)
            ->and($disk['files'][APACHE_CATCH_ALL])->toContain(
                '<VirtualHost *:80>', '<VirtualHost *:443>', 'ServerName panel-default.invalid',
                'Require all denied', '.panel-tls-reject.crt', '<IfModule ssl_module>',
            );
    });

    it('re-links a file someone disabled', function () {
        $disk = catchAllDisk();
        app(CatchAllSite::class)->ensure();
        $links = $disk['links'];
        unset($links[APACHE_CATCH_ALL_LINK]);
        $disk['links'] = $links;

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated')
            ->and($disk['links'])->toHaveKey(APACHE_CATCH_ALL_LINK);
    });

    it('removes both the file and the link when refused', function () {
        $disk = catchAllDisk(testPasses: false);

        app(CatchAllSite::class)->ensure();

        expect($disk['files'])->not->toHaveKey(APACHE_CATCH_ALL)
            ->and($disk['links'])->not->toHaveKey(APACHE_CATCH_ALL_LINK);
    });
});

describe('OpenLiteSpeed', function () {
    beforeEach(function () {
        catchAllServer('openlitespeed');
        config([
            'app.url' => 'https://api.example.test',
            'server.storage.panel_url' => 'https://panel.example.test',
        ]);
    });

    $httpd = fn (string $cert) => <<<CONF
        listener Defaultssl {
          address                 *:443
          secure                  1
          keyFile                 {$cert}/privkey.pem
          certFile                {$cert}/fullchain.pem
          certChain               1
          map                     panel panel.example.test
          map                     panel-api api.example.test
        }
        virtualHost panel {
          vhRoot                  /usr/local/lsws/conf/vhosts/panel/
          configFile              /usr/local/lsws/conf/vhosts/panel/vhconf.conf
        }

        CONF;
    $vhconf = "docRoot /var/www/panel\n\nvhssl {\n  keyFile                 /etc/letsencrypt/live/panel.example.test/privkey.pem\n  certFile                /etc/letsencrypt/live/panel.example.test/fullchain.pem\n}\n";

    it('stops showing the panel\'s certificate to unknown names', function () use ($httpd, $vhconf) {
        $disk = catchAllDisk([
            '/usr/local/lsws/conf/httpd_config.conf' => $httpd('/etc/letsencrypt/live/panel.example.test'),
            '/usr/local/lsws/conf/vhosts/panel/vhconf.conf' => $vhconf,
        ]);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated');

        $config = $disk['files']['/usr/local/lsws/conf/httpd_config.conf'];

        expect($config)->toContain('certFile                /etc/ssl/sv-oss/.panel-tls-reject.crt')
            ->toContain('keyFile                 /etc/ssl/sv-oss/.panel-tls-reject.key')
            // The panel keeps its own certificate in its vhost; untouched.
            ->and($disk['files']['/usr/local/lsws/conf/vhosts/panel/vhconf.conf'])->toBe($vhconf)
            ->and($disk['reloads'])->toBe(1);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('current');
    });

    it('leaves a listener certificate somebody chose on purpose', function () use ($httpd, $vhconf) {
        $original = $httpd('/etc/ssl/operator-wildcard');
        $disk = catchAllDisk([
            '/usr/local/lsws/conf/httpd_config.conf' => $original,
            '/usr/local/lsws/conf/vhosts/panel/vhconf.conf' => $vhconf,
        ]);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files']['/usr/local/lsws/conf/httpd_config.conf'])->toBe($original)
            ->and($disk['reloads'])->toBe(0);
    });
});

it('is part of sites:resync, which every install and update runs', function () {
    catchAllServer('nginx');
    $disk = catchAllDisk();

    $this->artisan('sites:resync')->expectsOutputToContain('Unknown domains: now refused')->assertSuccessful();

    expect($disk['files'])->toHaveKey(NGINX_CATCH_ALL);
});
