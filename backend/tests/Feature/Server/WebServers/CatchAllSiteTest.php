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
function catchAllDisk(array $files = [], string $nginx = '1.28.3', bool $testPasses = true, array $stock = []): ArrayObject
{
    $state = new ArrayObject(['files' => $files, 'links' => [], 'reloads' => 0, 'tests' => 0]);

    Process::fake(function ($process) use ($state, $nginx, $testPasses, $stock) {
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
                '-e' => isset($links[$args[2]]) || isset($files[$args[2]]) ? 0 : 1,
                default => 0,
            }),
            'find' => in_array('%l', $args, true)
                ? Process::result(output: $links[$args[1]] ?? '')
                : Process::result(),
            // The md5 dpkg keeps for each package conffile: `$stock` is the
            // package's original text of a file.
            'dpkg-query' => Process::result(output: collect($stock)
                ->map(fn (string $text, string $path) => " {$path} ".md5($text))->implode("\n")),
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

// v7's names (Dipali, 2026-11-11): what v7 has, where v7 has it.
const NGINX_CATCH_ALL = '/etc/nginx/sites-available/default';
const NGINX_CATCH_ALL_SSL = '/etc/nginx/sites-available/000-default-ssl.conf';
const NGINX_CATCH_ALL_LINK = '/etc/nginx/sites-enabled/default';
const NGINX_CATCH_ALL_LEGACY = '/etc/nginx/conf.d/panel-default-server.conf';
const APACHE_CATCH_ALL = '/etc/apache2/sites-available/000-default.conf';
const APACHE_CATCH_ALL_SSL = '/etc/apache2/sites-available/000-default-ssl.conf';
const APACHE_CATCH_ALL_LINK = '/etc/apache2/sites-enabled/000-default.conf';
const APACHE_CATCH_ALL_SSL_LINK = '/etc/apache2/sites-enabled/000-default-ssl.conf';
const APACHE_CATCH_ALL_LEGACY = '/etc/apache2/conf-available/panel-default-site.conf';

/** v7's own default site, as it is on a real v7 nginx server. */
const V7_NGINX_DEFAULT = "server {\n\tlisten 80 default_server;\n\troot /var/www/html;\n\tserver_name _;\n}\n";

describe('nginx', function () {
    beforeEach(fn () => catchAllServer('nginx'));

    it('answers unknown names itself, under v7\'s file names, refusing the TLS handshake', function () {
        $disk = catchAllDisk();

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated');

        expect($disk['files'][NGINX_CATCH_ALL])->toStartWith('# Managed by the panel')
            ->toContain('listen 80 default_server;', 'return 404;')
            ->not->toContain('443')
            ->and($disk['files'][NGINX_CATCH_ALL_SSL])->toContain('listen [::]:443 ssl default_server;', 'ssl_reject_handshake on;')
            ->not->toContain('ssl_certificate ')
            ->and($disk['links'])->toMatchArray([NGINX_CATCH_ALL_LINK => NGINX_CATCH_ALL, '/etc/nginx/sites-enabled/000-default-ssl.conf' => NGINX_CATCH_ALL_SSL])
            ->and($disk['reloads'])->toBe(1);
    });

    it('uses the reserved certificate on an nginx too old to refuse a handshake', function () {
        $disk = catchAllDisk(nginx: '1.18.0');

        app(CatchAllSite::class)->ensure();

        expect($disk['files'][NGINX_CATCH_ALL_SSL])->not->toContain('ssl_reject_handshake')
            ->toContain('.panel-tls-reject.crt', 'return 444;');
    });

    it('writes and reloads nothing the second time', function () {
        $disk = catchAllDisk();
        app(CatchAllSite::class)->ensure();

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('current')
            ->and($disk['reloads'])->toBe(1);
    });

    it('takes itself back out when the web server refuses it, and never reloads', function () {
        $disk = catchAllDisk(testPasses: false);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('failed')
            ->and($disk['files'])->not->toHaveKey(NGINX_CATCH_ALL)
            ->and($disk['files'])->not->toHaveKey(NGINX_CATCH_ALL_SSL)
            ->and($disk['links'])->toBe([])
            ->and($disk['reloads'])->toBe(0);
    });

    it('writes over Ubuntu\'s untouched default site, and over its own', function () {
        $stock = "server {\n\tlisten 80 default_server;\n\troot /var/www/html;\n}\n";
        $disk = catchAllDisk([NGINX_CATCH_ALL => $stock], stock: [NGINX_CATCH_ALL => $stock]);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated')
            ->and($disk['files'][NGINX_CATCH_ALL])->toStartWith('# Managed by the panel');
    });

    it('leaves v7\'s or the owner\'s own default exactly as it is, as on v7', function () {
        // Ubuntu's file with one line changed is no longer the package's.
        $disk = catchAllDisk(
            [NGINX_CATCH_ALL => V7_NGINX_DEFAULT, NGINX_CATCH_ALL_LEGACY => 'the panel\'s earlier answer'],
            stock: [NGINX_CATCH_ALL => V7_NGINX_DEFAULT."# changed\n"],
        );

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files'][NGINX_CATCH_ALL])->toBe(V7_NGINX_DEFAULT)
            ->and($disk['files'])->not->toHaveKey(NGINX_CATCH_ALL_SSL)
            // Unknown names stay answered by what answered them.
            ->and($disk['files'][NGINX_CATCH_ALL_LEGACY])->toBe('the panel\'s earlier answer')
            ->and($disk['reloads'])->toBe(0);
    });

    it('leaves a file somebody put where the link goes', function () {
        $disk = catchAllDisk([NGINX_CATCH_ALL_LINK => '# keeps the stock site out']);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('skipped')
            ->and($disk['files'][NGINX_CATCH_ALL_LINK])->toBe('# keeps the stock site out');
    });

    it('moves its earlier answer to v7\'s names in one change', function () {
        // Both at once would be two default_server blocks, which nginx refuses.
        $disk = catchAllDisk([NGINX_CATCH_ALL_LEGACY => 'the panel\'s earlier answer']);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated')
            ->and($disk['files'])->not->toHaveKey(NGINX_CATCH_ALL_LEGACY)
            ->and($disk['files'])->toHaveKey(NGINX_CATCH_ALL)
            ->and($disk['tests'])->toBe(1);
    });

    it('puts everything back when the move is refused', function () {
        $disk = catchAllDisk([NGINX_CATCH_ALL_LEGACY => 'the panel\'s earlier answer'], testPasses: false);

        app(CatchAllSite::class)->ensure();

        expect(array_map('trim', $disk['files']))->toBe([NGINX_CATCH_ALL_LEGACY => 'the panel\'s earlier answer'])
            ->and($disk['links'])->toBe([]);
    });
});

describe('Apache', function () {
    beforeEach(fn () => catchAllServer('apache'));

    it('loads before every site and denies, with the reserved certificate on 443', function () {
        $disk = catchAllDisk();

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated');

        expect($disk['links'])->toMatchArray([APACHE_CATCH_ALL_LINK => APACHE_CATCH_ALL, APACHE_CATCH_ALL_SSL_LINK => APACHE_CATCH_ALL_SSL])
            ->and($disk['files'][APACHE_CATCH_ALL])->toContain('<VirtualHost *:80>', 'ServerName panel-default.invalid', 'Require all denied')
            ->not->toContain('<VirtualHost *:443>')
            ->and($disk['files'][APACHE_CATCH_ALL_SSL])->toContain('<VirtualHost *:443>', '.panel-tls-reject.crt', '<IfModule ssl_module>');
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

    it('removes the files and the links when refused', function () {
        $disk = catchAllDisk(testPasses: false);

        app(CatchAllSite::class)->ensure();

        expect($disk['files'])->toBe([])
            ->and($disk['links'])->toBe([]);
    });

    it('takes over Ubuntu\'s untouched 000-default.conf and drops its earlier conf-enabled copy', function () {
        $stock = "<VirtualHost *:80>\n\tDocumentRoot /var/www/html\n</VirtualHost>\n";
        $disk = catchAllDisk([APACHE_CATCH_ALL => $stock, APACHE_CATCH_ALL_LEGACY => 'earlier'], stock: [APACHE_CATCH_ALL => $stock]);

        expect(app(CatchAllSite::class)->ensure()['status'])->toBe('updated')
            ->and($disk['files'][APACHE_CATCH_ALL])->toContain('panel-default.invalid')
            ->and($disk['files'])->not->toHaveKey(APACHE_CATCH_ALL_LEGACY);
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
