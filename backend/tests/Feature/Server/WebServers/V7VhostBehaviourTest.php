<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/*
 * What v7's vhosts did that v8's had dropped (compared line by line against
 * real v7 servers, 2026-11-08). Dipali: vhosts the same as v7, so a site moved
 * from v7 behaves as it did once v8 rewrites its vhost.
 */

beforeEach(function () {
    Process::fake();
    $this->user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

function behaviourSite(string $webServer, array $attributes = [], bool $tls = true): Application
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => ['nginx' => 'lemp', 'apache' => 'lamp', 'openlitespeed' => 'ols'][$webServer],
        'web_server' => $webServer, 'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);
    app()->forgetInstance(WebServerManager::class);
    Certificate::query()->delete();
    Application::query()->each(fn (Application $a) => $a->domains()->delete());
    Application::query()->delete();

    $app = Application::forceCreate($attributes + [
        'system_user_id' => test()->user->id,
        'name' => 'blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4', 'isolated_at' => now(),
    ]);
    $app->domains()->create(['domain' => 'blog.example.com', 'type' => DomainType::Primary]);

    if ($tls) {
        Certificate::create([
            'application_id' => $app->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
            'domains' => ['blog.example.com'],
            'certificate_path' => '/etc/letsencrypt/live/blog/fullchain.pem',
            'private_key_path' => '/etc/letsencrypt/live/blog/privkey.pem',
            'issued_at' => now(), 'expires_at' => now()->addDays(80),
        ]);
    }

    return $app->fresh(['domains', 'certificate', 'systemUser', 'phpSettings']);
}

/** @return array<string, string> file => contents, as the driver writes them */
function behaviourFiles(Application $app, string $driver): array
{
    return array_filter(app($driver)->configFiles($app, '/home/siteowner/blog/public_html'));
}

it('gives nginx PHP v7\'s 300 seconds, or longer when PHP may run longer', function () {
    $config = implode("\n", behaviourFiles(behaviourSite('nginx'), NginxDriver::class));
    expect($config)->toContain('fastcgi_read_timeout 300;');

    $site = behaviourSite('nginx');
    $site->phpSettings()->create(['max_execution_time' => 600]);
    $config = implode("\n", behaviourFiles($site->fresh(['domains', 'certificate', 'systemUser', 'phpSettings']), NginxDriver::class));

    expect($config)->toContain('fastcgi_read_timeout 630;');
});

it('caches static files the way v7 did, except on Nextcloud', function () {
    $config = implode("\n", behaviourFiles(behaviourSite('nginx'), NginxDriver::class));
    expect($config)->toContain('expires 30d;')->toContain('expires 7d;')->toContain('expires 1h;');

    $nextcloud = implode("\n", behaviourFiles(behaviourSite('nginx', ['site_type' => 'nextcloud']), NginxDriver::class));
    expect($nextcloud)->not->toContain('expires 30d;');

    expect(implode("\n", behaviourFiles(behaviourSite('apache'), ApacheDriver::class)))->toContain('ExpiresActive on');
    expect(implode("\n", behaviourFiles(behaviourSite('openlitespeed'), OlsDriver::class)))->toContain('enableExpires           1');
});

it('logs the HTTPS block to v7\'s -ssl files and the HTTP block to the plain ones', function (string $webServer, string $driver, string $sslLine, string $plainLine) {
    $files = behaviourFiles(behaviourSite($webServer), $driver);
    $ssl = collect($files)->first(fn ($c, $path) => str_ends_with($path, '-le-ssl.conf'));
    $main = collect($files)->first(fn ($c, $path) => str_ends_with($path, '/blog.conf'));

    expect($ssl)->toContain($sslLine)->not->toContain($plainLine)
        ->and($main)->not->toContain($sslLine);
})->with([
    'nginx' => ['nginx', NginxDriver::class, '/home/siteowner/blog/logs/access-ssl.log;', '/home/siteowner/blog/logs/access.log;'],
    'apache' => ['apache', ApacheDriver::class, '/home/siteowner/blog/logs/access-ssl.log combined', '/home/siteowner/blog/logs/access.log combined'],
]);

it('passes the Authorization header to PHP on Apache, as v7 did', function () {
    expect(implode("\n", behaviourFiles(behaviourSite('apache', tls: false), ApacheDriver::class)))
        ->toContain('SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1');
});

it('offers HTTP/2 on Apache HTTPS, only where the module is loaded', function () {
    $ssl = collect(behaviourFiles(behaviourSite('apache'), ApacheDriver::class))
        ->first(fn ($c, $path) => str_ends_with($path, '-le-ssl.conf'));

    expect($ssl)->toMatch('#<IfModule http2_module>\s*Protocols h2 http/1.1\s*</IfModule>#');
});

it('gives each OpenLiteSpeed site v7\'s 35 PHP workers', function () {
    $config = implode("\n", behaviourFiles(behaviourSite('openlitespeed'), OlsDriver::class));

    expect($config)->toMatch('/maxConns\s+35/')->toContain('PHP_LSAPI_CHILDREN=35');
});

it('renders a Node site\'s own v7 location block instead of the default proxy', function () {
    $custom = "index index.html;\n\nlocation / {\n    proxy_pass http://127.0.0.1:50424;\n    proxy_set_header X-Custom yes;\n    {basic_authentication}\n}";
    $site = behaviourSite('nginx', ['site_type' => 'node', 'serving_profile' => 'node', 'app_port' => 50424, 'nginx_location' => $custom]);

    $config = implode("\n", behaviourFiles($site, NginxDriver::class));

    expect($config)->toContain('proxy_set_header X-Custom yes;')
        ->not->toContain('{basic_authentication}')
        ->not->toContain('error_page 502 503 504 =503 @restarting;');

    $default = implode("\n", behaviourFiles(behaviourSite('nginx', ['site_type' => 'node', 'serving_profile' => 'node', 'app_port' => 3000]), NginxDriver::class));
    expect($default)->toContain('proxy_pass http://127.0.0.1:3000;')->toContain('@restarting');
});

it('adds and removes the nginx_location column', function () {
    $migration = require database_path('migrations/2026_11_08_000001_add_nginx_location_to_applications.php');

    $migration->down();
    expect(Schema::hasColumn('applications', 'nginx_location'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('applications', 'nginx_location'))->toBeTrue();
});
