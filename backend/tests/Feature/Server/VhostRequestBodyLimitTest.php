<?php

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\SystemUser;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;

/**
 * The web server must accept a body as large as the site's PHP will.
 *
 * A 413 comes from the web server before PHP is reached, so no PHP setting can fix it. nginx
 * defaults to 1 MB; Apache and OpenLiteSpeed are covered too so all three behave the same.
 * Rendered templates are checked, not a live server.
 */
beforeEach(function () {
    $this->systemUser = SystemUser::create([
        'username' => 'siteowner',
        'home_path' => '/home/siteowner',
    ]);
});

function bodyLimitSite(array $phpSettings = []): Application
{
    $application = Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    if ($phpSettings !== []) {
        $application->phpSettings()->create($phpSettings);
    }

    return $application->load(['systemUser', 'phpSettings']);
}

/** @return array<string, string> web server => the whole rendered config */
function renderedVhosts(Application $application): array
{
    $root = '/home/siteowner/blog/public_html';

    return [
        'nginx' => app(NginxDriver::class)->renderConfig($application, $root),
        'apache' => app(ApacheDriver::class)->renderConfig($application, $root),
        'openlitespeed' => app(OlsDriver::class)->renderConfig($application, $root),
    ];
}

it('carries the size the user actually set', function () {
    // 512 MiB: set in PHP, must not be ignored by the web server.
    $rendered = renderedVhosts(bodyLimitSite(['post_max_size' => '512M']));

    expect($rendered['nginx'])->toContain('client_max_body_size 536870912;')
        ->and($rendered['apache'])->toContain('LimitRequestBody 536870912')
        ->and($rendered['openlitespeed'])->toContain('maxReqBodySize            536870912');
});

it('falls back to the panel default for a site that set nothing', function () {
    // 128 MiB: the same number `ApplicationPhpSettings::defaults()` writes into the pool.
    $rendered = renderedVhosts(bodyLimitSite());

    expect($rendered['nginx'])->toContain('client_max_body_size 134217728;')
        ->and($rendered['apache'])->toContain('LimitRequestBody 134217728')
        ->and($rendered['openlitespeed'])->toContain('maxReqBodySize            134217728');
});

it('is a real number even when PHP says unlimited', function () {
    // `post_max_size = 0` means "no limit" in PHP, but OpenLiteSpeed reads 0 as "reject every body".
    $rendered = renderedVhosts(bodyLimitSite(['post_max_size' => '0']));

    expect($rendered['openlitespeed'])->toContain('maxReqBodySize            1048576')
        ->and($rendered['nginx'])->not->toContain('client_max_body_size 0;');
});

it('never leaves a site on the 1 MB nginx default', function () {
    // Without a directive nginx applies its own 1 MB, so assert the directive is present.
    $config = renderedVhosts(bodyLimitSite(['post_max_size' => '512M']))['nginx'];

    expect($config)->toContain('client_max_body_size');
});

it('applies to node sites too, which also receive uploads', function () {
    $application = Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => 'App', 'slug' => 'app', 'domain' => 'app.example.com',
        'site_type' => 'git', 'serving_profile' => 'node',
        'status' => 'active', 'web_root' => '/', 'app_port' => 3300,
    ]);
    $application->load('systemUser');

    // A reverse proxy passes the body through, but the limit in front of it is still the web server's.
    expect(app(NginxDriver::class)->renderConfig($application, '/home/siteowner/app/public_html'))
        ->toContain('client_max_body_size');
});

it('agrees with the number PHP is given', function () {
    // Whatever the site's settings resolve to is what the web server enforces.
    $application = bodyLimitSite(['post_max_size' => '256M']);

    $fromSettings = ApplicationPhpSettings::toBytes(
        (string) $application->phpSettings->effective()['post_max_size']
    );

    expect(renderedVhosts($application)['nginx'])
        ->toContain("client_max_body_size {$fromSettings};");
});
