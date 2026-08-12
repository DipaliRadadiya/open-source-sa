<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationEnvironment;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ReleaseManager;
use App\Services\Server\WebServers\OlsDriver;

/**
 * Where a site lives on disk, asserted once.
 *
 * `{home}/{slug}` was hand-built in five places and a sixth used `domain`
 * instead — OpenLiteSpeed's vhost root, which is both the log directory and
 * the directory `restrained 1` confines the vhost to. That put the document
 * root outside the restraint, and moved a live site's logs whenever someone
 * changed its domain.
 *
 * Slug rather than domain is the whole point: a domain is a pointer the user
 * can repoint at any time, and a path that follows it orphans every file
 * already written under the old one.
 */
beforeEach(function () {
    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    $this->application->load('systemUser');
});

it('anchors a site on its slug, never its domain', function () {
    expect($this->application->rootPath())->toBe('/home/siteowner/shop');
});

it('keeps the path put when the domain changes', function () {
    $before = $this->application->rootPath();

    $this->application->forceFill(['domain' => 'newshop.example.com'])->save();

    expect($this->application->fresh()->load('systemUser')->rootPath())->toBe($before);
});

it('does not collapse onto the system user home when a legacy row has no slug', function () {
    // Rows predating the slug column. `{home}/` would resolve to the home
    // directory itself — which every one of that user's sites would share.
    $this->application->forceFill(['slug' => null])->save();

    expect($this->application->fresh()->load('systemUser')->rootPath())
        ->toBe('/home/siteowner')
        ->not->toEndWith('/');
});

it('puts every derived path inside the site root', function () {
    $root = $this->application->rootPath();

    $paths = [
        'document root' => app(ApplicationProvisioner::class)->documentRoot($this->application),
        'env file' => app(ApplicationEnvironment::class)->path($this->application),
        'releases' => app(ReleaseManager::class)->appRoot($this->application),
    ];

    foreach ($paths as $label => $path) {
        expect($path)->toStartWith($root, "{$label} escaped the site root");
    }
});

it('confines the OpenLiteSpeed vhost to a directory that contains the document root', function () {
    // The bug this file exists for. `restrained 1` points OLS at the vhost
    // root; if the document root is not underneath it, the site cannot be
    // served at all.
    $vhRoot = (function (Application $application) {
        $method = new ReflectionMethod(OlsDriver::class, 'vhRoot');

        return $method->invoke(app(OlsDriver::class), $application);
    })($this->application);

    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($this->application);

    expect($vhRoot)->toBe('/home/siteowner/shop')
        ->and($documentRoot)->toStartWith($vhRoot.'/')
        // The specific thing that was wrong, named so a revert is obvious.
        ->and($vhRoot)->not->toContain('shop.example.com');
});
