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
 * Reported 2026-09-08: a WordPress media upload answered "413 Request Entity
 * Too Large". The user had already raised `post_max_size` and
 * `upload_max_filesize` to 512M and it changed nothing — which is the tell.
 * A 413 comes from the web server, which rejects the request *before* PHP is
 * reached, so no PHP setting can affect it.
 *
 * The cause was that no vhost template on any of the three web servers set a
 * body limit at all. nginx's built-in default is 1 MB, so every nginx site was
 * capped there while the panel wrote `upload_max_filesize = 64M` into its pool
 * and the PHP Settings screen displayed 64 MB. The screen was not lying about
 * what it had written; it was describing a number the web server overruled.
 *
 * Apache and OpenLiteSpeed default to effectively unlimited and so were never
 * the source of a 413 — they are covered here anyway, because a limit that
 * exists on one web server and not the others is the same site behaving
 * differently depending on which box it lands on.
 *
 * Rendered templates rather than a live server: the question is what the
 * config says, and that is answerable without the daemon.
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
    // 512 MiB. The exact case reported: set in PHP, ignored by the web server.
    $rendered = renderedVhosts(bodyLimitSite(['post_max_size' => '512M']));

    expect($rendered['nginx'])->toContain('client_max_body_size 536870912;')
        ->and($rendered['apache'])->toContain('LimitRequestBody 536870912')
        ->and($rendered['openlitespeed'])->toContain('maxReqBodySize            536870912');
});

it('falls back to the panel default for a site that set nothing', function () {
    // 64 MiB — the same number `ApplicationPhpSettings::defaults()` writes into
    // the pool, so the two layers agree without either being told about the
    // other.
    $rendered = renderedVhosts(bodyLimitSite());

    expect($rendered['nginx'])->toContain('client_max_body_size 67108864;')
        ->and($rendered['apache'])->toContain('LimitRequestBody 67108864')
        ->and($rendered['openlitespeed'])->toContain('maxReqBodySize            67108864');
});

it('is a real number even when PHP says unlimited', function () {
    // `post_max_size = 0` is legal PHP for "no limit". nginx and Apache read 0
    // the same way, but OpenLiteSpeed reads it as *reject every body* — one
    // value meaning two opposite things is how a site ends up refusing all
    // uploads on one web server and none on another.
    $rendered = renderedVhosts(bodyLimitSite(['post_max_size' => '0']));

    expect($rendered['openlitespeed'])->toContain('maxReqBodySize            1048576')
        ->and($rendered['nginx'])->not->toContain('client_max_body_size 0;');
});

it('never leaves a site on the 1 MB nginx default', function () {
    // The regression that produced the report. Without a directive nginx
    // applies its own 1 MB, and nothing in the rendered file says so — the
    // absence is the bug, which is why this asserts presence rather than a
    // value.
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

    // A reverse proxy passes the body through; the limit in front of it is
    // still the web server's.
    expect(app(NginxDriver::class)->renderConfig($application, '/home/siteowner/app/public_html'))
        ->toContain('client_max_body_size');
});

it('agrees with the number PHP is given', function () {
    // The property that matters, stated once: whatever the site's settings
    // resolve to is what the web server enforces. Two layers, one source.
    $application = bodyLimitSite(['post_max_size' => '256M']);

    $fromSettings = ApplicationPhpSettings::toBytes(
        (string) $application->phpSettings->effective()['post_max_size']
    );

    expect(renderedVhosts($application)['nginx'])
        ->toContain("client_max_body_size {$fromSettings};");
});
