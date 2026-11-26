<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\WebServers\NginxDriver;
use Illuminate\Support\Facades\Process;

/**
 * Paths an application protects with Apache `.htaccess` files.
 *
 * nginx never reads `.htaccess`, and OpenLiteSpeed only where the vhost turns it on, so
 * on both the apps' log files (e.g. Akaunting's `storage/logs/laravel.log`) would be
 * served to anyone. Apache reads the apps' own files and needs nothing.
 */
beforeEach(function () {
    $this->home = sys_get_temp_dir().'/sv-oss-denied-'.getmypid();

    config([
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.web_server_drivers.openlitespeed.sites_dir' => $this->home.'/sites',
    ]);

    $this->systemUser = SystemUser::create([
        'username' => 'appuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
});

function deniedPathsVhost(string $siteType, string $driver): string
{
    config(['server.web_server' => $driver]);

    $application = Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => $siteType, 'slug' => $siteType, 'domain' => "{$siteType}.example.com",
        'site_type' => $siteType, 'serving_profile' => 'php', 'php_version' => '8.4',
        'web_root' => '/', 'status' => 'active',
    ]);
    $application->domains()->create(['domain' => "{$siteType}.example.com", 'type' => DomainType::Primary]);

    return app((string) config("server.web_server_drivers.{$driver}.driver"))->renderConfig(
        $application->fresh(['domains', 'certificate', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($application),
    );
}

it('denies what the application protects on Apache, before PHP can run it, on nginx', function (string $siteType, array $mustDeny) {
    $config = deniedPathsVhost($siteType, 'nginx');
    $php = strpos($config, 'location ~ [^/]\.php(/|$)');

    foreach (app(SiteTypeManager::class)->find($siteType)->deniedPaths() as $pattern) {
        $rule = strpos($config, "location ~* {$pattern} {\n        deny all;");

        // Regex locations are tried in order: after the PHP one, a denied `.php` would still run.
        expect($rule)->not->toBeFalse()->and($rule)->toBeLessThan($php);
    }

    foreach ($mustDeny as $path) {
        expect(collect(app(SiteTypeManager::class)->find($siteType)->deniedPaths())
            ->contains(fn (string $pattern) => preg_match('~'.$pattern.'~i', $path) === 1))
            ->toBeTrue("{$path} is not denied");
    }
})->with([
    'akaunting' => ['akaunting', ['/storage/logs/laravel.log', '/storage/framework/sessions/abc', '/artisan', '/composer.json', '/vendor/autoload.php', '/config/app.php', '/.env']],
    'prestashop' => ['prestashop', ['/var/logs/prod-2026-09-26.log', '/app/config/parameters.php', '/vendor/autoload.php', '/modules/ps_mbo/vendor/x.php', '/upload/shell.php', '/img/x.php', '/themes/classic/templates/index.tpl', '/composer.lock']],
    // Extensionless scripts such as bin/console must be denied too, or they download.
    'mautic' => ['mautic', ['/vendor/symfony/console/Application.php', '/config/local.php', '/app/AppKernel.php', '/plugins/MauticFocusBundle/Config/config.php', '/media/files/shell.php', '/var/logs/mautic_prod-2026-10-04.php', '/var/spool/default/x.message', '/bin/console', '/bin/composer', '/autoload.php', '/importmap.php', '/.env', '/.env.local', '/composer.json']],
]);

it('still serves what the application needs', function (string $siteType, array $mustServe) {
    $patterns = app(SiteTypeManager::class)->find($siteType)->deniedPaths();

    foreach ($mustServe as $path) {
        expect(collect($patterns)->contains(fn (string $pattern) => preg_match('~'.$pattern.'~i', $path) === 1))
            ->toBeFalse("{$path} would be blocked");
    }
})->with([
    'akaunting' => ['akaunting', ['/', '/index.php', '/public/css/app.css', '/public/js/akaunting.min.js', '/vendor/some/pkg/dist/app.js', '/modules/Foo/Resources/assets/logo.png', '/auth/login']],
    'prestashop' => ['prestashop', ['/', '/index.php', '/admin172vrcqtzvkqymg2kse/index.php', '/img/p/1/1.jpg', '/themes/classic/assets/css/theme.css', '/js/jquery/jquery-3.7.1.min.js', '/modules/ps_mbo/views/img/logo.png', '/api/products']],
    'mautic' => ['mautic', ['/', '/index.php', '/s/login', '/s/dashboard', '/media/js/app.js', '/media/css/app.css', '/media/images/mautic_logo.png', '/plugins/MauticFocusBundle/Assets/js/focus.js', '/themes/blank/html/base.html.twig', '/mtc.js', '/form/submit']],
]);

it('renders them as deny contexts on OpenLiteSpeed', function (string $siteType) {
    $config = deniedPathsVhost($siteType, 'openlitespeed');

    foreach (app(SiteTypeManager::class)->find($siteType)->deniedPaths() as $pattern) {
        expect($config)->toContain("context exp:{$pattern} {\n  allowBrowse             0\n}");
    }
})->with(['akaunting', 'prestashop', 'mautic']);

it('adds nothing for an application that needs nothing', function (string $driver) {
    $config = deniedPathsVhost('wordpress', $driver);

    // The three browser-caching locations are always present on nginx; nothing beyond them.
    expect(substr_count($config, 'location ~* ') - substr_count($config, 'location ~* \\.(?:'))->toBe(0)
        // OLS: only the dotfile context it always had.
        ->and(substr_count($config, 'context exp:'))->toBe($driver === 'openlitespeed' ? 1 : 0);
})->with(['nginx', 'openlitespeed']);

/*
| PrestaShop's back office is its own application in `admin<random>/`, and its
| `.htaccess` sends every path there to that folder's index.php, not the shop's
| front controller.
*/

it('routes PrestaShop\'s back office folder to its own front controller on nginx, after every other rule', function () {
    $config = deniedPathsVhost('prestashop', 'nginx');

    $admin = strpos($config, 'location ~ ^/(?<frontdir0>admin[a-z0-9]+)/ {');
    $body = substr($config, $admin, 200);

    expect($admin)->not->toBeFalse()
        ->and($body)->toContain('try_files $uri $uri/ /$frontdir0/index.php$is_args$args;')
        // First-match regex locations: placed before these, a .php file would be served as source.
        ->and($admin)->toBeGreaterThan(strpos($config, 'location ~ [^/]\.php(/|$)'))
        ->and($admin)->toBeGreaterThan(strpos($config, 'location ~ /\.(?!well-known)'))
        ->and($admin)->toBeGreaterThan(strpos($config, 'location ~* \.(log|tpl|twig|sass|yml)$'));
});

it('routes PrestaShop\'s back office folder before the shop\'s front controller on OpenLiteSpeed', function () {
    $config = deniedPathsVhost('prestashop', 'openlitespeed');

    $admin = strpos($config, 'RewriteRule ^/(admin[a-z0-9]+)/ /$1/index.php [L]');

    expect($admin)->not->toBeFalse()
        ->and($admin)->toBeLessThan(strpos($config, 'RewriteRule ^/index\.php$ - [L]'))
        // Real files and folders are served as they are; the rest goes to the back office's index.php.
        ->and(substr($config, $admin - 120, 120))->toContain('RewriteCond %{REQUEST_FILENAME} !-f')
        ->and(substr($config, $admin - 120, 120))->toContain('RewriteCond %{REQUEST_FILENAME} !-d');
});

it('leaves PrestaShop 9\'s admin-api folder to the shop', function () {
    $front = app(SiteTypeManager::class)->find('prestashop')->subdirectoryFrontControllers()[0];

    expect(preg_match('~^/('.$front['directory'].')/~', '/admin172vrcqtzvkqymg2kse/login'))->toBe(1)
        ->and(preg_match('~^/('.$front['directory'].')/~', '/admin-api/index.php/x'))->toBe(0);
});

it('adds no front controller for a site type without one', function (string $driver) {
    $config = deniedPathsVhost('wordpress', $driver);

    expect($config)->not->toContain('frontdir')
        ->not->toContain('RewriteRule ^/(admin');
})->with(['nginx', 'openlitespeed']);

/*
| Nextcloud's .htaccess routes CalDAV/CardDAV discovery and the rest of
| /.well-known, and serves .mjs as JavaScript.
*/

it('routes Nextcloud\'s well-known URIs and module type on nginx, ACME still first', function () {
    $config = deniedPathsVhost('nextcloud', 'nginx');

    expect($config)->toContain("location = /.well-known/caldav {\n        return 301 /remote.php/dav/;")
        ->toContain("location = /.well-known/carddav {\n        return 301 /remote.php/dav/;")
        ->toContain('return 301 /index.php$request_uri;')
        ->toContain('location ^~ /.well-known/pki-validation/')
        ->toContain("location ~* \\.mjs$ {\n        types { }\n        default_type text/javascript;")
        // A longer prefix than /.well-known/, so certificate issuance still
        // reaches the challenge directory.
        ->toContain('location ^~ /.well-known/acme-challenge/');
});

it('routes Nextcloud\'s well-known URIs on OpenLiteSpeed, ACME and PKI exempt', function () {
    $config = deniedPathsVhost('nextcloud', 'openlitespeed');

    expect($config)->toContain('RewriteRule ^/\.well-known/caldav$ /remote.php/dav/ [R=301,L]')
        ->toContain('RewriteCond %{REQUEST_URI} !^/\.well-known/(acme-challenge|pki-validation)/')
        ->toContain('RewriteRule ^/\.well-known/ /index.php [L]');
});

it('adds no well-known routing or types for other site types', function (string $driver) {
    $config = deniedPathsVhost('wordpress', $driver);

    expect($config)->not->toContain('/remote.php/dav/')->not->toContain('default_type text/javascript');
})->with(['nginx', 'openlitespeed']);

it('uses `http2 on;` on an nginx that has it, and the old listen flag on one that does not', function (string $version, bool $modern) {
    // nginx 1.28 warns about the old listen flag on every `nginx -t`; on 1.24 `http2 on;` is an error.
    Process::fake(fn ($process) => in_array('-v', $process->command, true)
        ? Process::result(errorOutput: "nginx version: nginx/{$version} (Ubuntu)\n")
        : Process::result(exitCode: 0));

    $application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id, 'name' => 'tls', 'slug' => 'tls', 'domain' => 'tls.example.com',
        'site_type' => 'php', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);
    $application->domains()->create(['domain' => 'tls.example.com', 'type' => DomainType::Primary]);
    Certificate::create([
        'application_id' => $application->id, 'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active, 'domains' => ['tls.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/tls/fullchain.pem', 'private_key_path' => '/etc/letsencrypt/live/tls/privkey.pem',
    ]);
    config(['server.web_server' => 'nginx']);

    $config = app(NginxDriver::class)->renderConfig(
        $application->fresh(['domains', 'certificate', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($application),
    );

    if ($modern) {
        expect($config)->toContain("listen 443 ssl;\n    listen [::]:443 ssl;\n    http2 on;")->not->toContain('ssl http2');
    } else {
        expect($config)->toContain('listen 443 ssl http2;')->not->toContain('http2 on;');
    }
})->with([
    'nginx 1.28 (Ubuntu 26.04)' => ['1.28.3', true],
    'nginx 1.25.1' => ['1.25.1', true],
    'nginx 1.24 (Ubuntu 24.04)' => ['1.24.0', false],
]);

/*
 * nginx 1.24 prints "protocol options redefined" when port-443 servers listen with
 * different options (`ssl http2` vs `ssl` only). Every one must ask for the same.
 */
it('asks for the same protocol options on every port-443 listen it writes', function (string $version) {
    Process::fake(fn ($process) => in_array('-v', $process->command, true)
        ? Process::result(errorOutput: "nginx version: nginx/{$version} (Ubuntu)\n")
        : Process::result(exitCode: 0));
    config(['server.web_server' => 'nginx']);

    $site = function (string $slug, bool $tls) {
        $application = Application::forceCreate([
            'system_user_id' => $this->systemUser->id, 'name' => $slug, 'slug' => $slug, 'domain' => "{$slug}.example.com",
            'site_type' => 'php', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
        ]);
        $application->domains()->create(['domain' => "{$slug}.example.com", 'type' => DomainType::Primary]);
        if ($tls) {
            Certificate::create([
                'application_id' => $application->id, 'type' => CertificateType::LetsEncrypt,
                'status' => CertificateStatus::Active, 'domains' => ["{$slug}.example.com"],
                'certificate_path' => "/etc/letsencrypt/live/{$slug}/fullchain.pem", 'private_key_path' => "/etc/letsencrypt/live/{$slug}/privkey.pem",
            ]);
        }

        return app(NginxDriver::class)->renderConfig(
            $application->fresh(['domains', 'certificate', 'systemUser']),
            app(ApplicationProvisioner::class)->documentRoot($application),
        );
    };

    $configs = [$site('withtls', true), $site('notls', false), ...array_values(app(NginxDriver::class)->catchAllConfig()['files'])];

    // Protocol options of each `listen ... 443 ...`, minus the per-server ones nginx does not compare.
    $options = collect($configs)
        ->flatMap(fn (string $c) => preg_match_all('/^\s*listen\s+(?:\[::\]:)?443\b([^;]*);/m', $c, $m) ? $m[1] : [])
        ->map(fn (string $o) => trim(preg_replace('/\b(default_server|ipv6only=\S+)\b/', '', $o)))
        ->map(fn (string $o) => preg_replace('/\s+/', ' ', $o))
        ->unique()->values()->all();

    expect($options)->toBe([version_compare($version, '1.25.1', '>=') ? 'ssl' : 'ssl http2']);
})->with(['nginx 1.24 (Ubuntu 24.04)' => ['1.24.0'], 'nginx 1.28 (Ubuntu 26.04)' => ['1.28.3']]);
