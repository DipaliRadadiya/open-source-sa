<?php

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use Illuminate\Support\Facades\Process;

/**
 * Paths an application protects with Apache `.htaccess` files.
 *
 * nginx never reads `.htaccess`, and OpenLiteSpeed only where the vhost turns
 * it on, so on both of them Akaunting's `storage/logs/laravel.log` and
 * PrestaShop's `var/logs/prod-*.log` answered 200 to anyone — measured on the
 * test servers. Apache reads the apps' own files and needs nothing.
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

        // Regex locations are tried in order: after the PHP one, a denied
        // `.php` (vendor/autoload.php, an upload) would still run.
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
]);

it('renders them as deny contexts on OpenLiteSpeed', function (string $siteType) {
    $config = deniedPathsVhost($siteType, 'openlitespeed');

    foreach (app(SiteTypeManager::class)->find($siteType)->deniedPaths() as $pattern) {
        expect($config)->toContain("context exp:{$pattern} {\n  allowBrowse             0\n}");
    }
})->with(['akaunting', 'prestashop']);

it('adds nothing for an application that needs nothing', function (string $driver) {
    $config = deniedPathsVhost('wordpress', $driver);

    expect(substr_count($config, 'location ~* '))->toBe(0)
        // OLS: only the dotfile context it always had.
        ->and(substr_count($config, 'context exp:'))->toBe($driver === 'openlitespeed' ? 1 : 0);
})->with(['nginx', 'openlitespeed']);
