<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns the default branding info without authentication', function () {
    $response = $this->getJson('/api/branding');

    $response->assertOk()
        ->assertJsonPath('branding.name', 'ServerAvatar')
        ->assertJsonPath('branding.logo', '/brand/serveravatar-horizontal-light.svg')
        ->assertJsonPath('branding.logo_dark', '/brand/serveravatar-horizontal-dark.svg')
        ->assertJsonPath('branding.icon', '/brand/serveravatar-mark-light.svg')
        ->assertJsonPath('branding.icon_dark', '/brand/serveravatar-mark-dark.svg')
        ->assertJsonPath('branding.favicon', '/brand/serveravatar-favicon.svg')
        ->assertJsonPath('branding.primary_color', '#35B87A');
});

it('reflects branding overrides from config', function () {
    config(['branding.name' => 'Custom Brand', 'branding.primary_color' => '#ff0000']);

    $response = $this->getJson('/api/branding');

    $response->assertOk()
        ->assertJsonPath('branding.name', 'Custom Brand')
        ->assertJsonPath('branding.primary_color', '#ff0000');
});

it('points every default logo at a file the frontend ships', function () {
    // Paths, not URLs: the browser loads them from the panel's own address, so a
    // renamed file in frontend/public/brand would break the sidebar silently.
    foreach (['logo', 'logo_dark', 'icon', 'icon_dark', 'favicon'] as $key) {
        $path = config("branding.{$key}");

        expect($path)->toStartWith('/brand/')
            ->and(base_path('../frontend/public'.$path))->toBeFile();
    }
});

/*
 * Branding from Central's public site settings (2026-10-20, operator's call):
 * the panel shows what Central publishes, falls back to its own value by
 * value, and never breaks when Central is down.
 */
describe('from Central', function () {
    beforeEach(function () {
        config(['central.url' => 'https://central.test']);
        Cache::flush();
    });

    function centralSays(array $settings, int $status = 200): void
    {
        Http::fake(['central.test/api/v1/site-settings' => Http::response(['data' => $settings], $status)]);
    }

    it('shows Central\'s name, logo, icon, favicon and colour', function () {
        centralSays([
            'name' => 'Acme Hosting', 'tag_line' => 'Hosting made easy',
            'logo_url' => 'https://cdn.acme.test/logo.png', 'icon_url' => 'https://cdn.acme.test/icon.png',
            'favicon_url' => 'https://cdn.acme.test/fav.ico', 'color_code' => '35B87A',
            'privacy_policy_link' => 'https://acme.test/privacy', 'terms_link' => 'https://acme.test/terms',
            'display_message' => 'Maintenance on Sunday',
        ]);

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('branding.source', 'central')
            ->assertJsonPath('branding.name', 'Acme Hosting')
            ->assertJsonPath('branding.logo', 'https://cdn.acme.test/logo.png')
            ->assertJsonPath('branding.logo_dark', 'https://cdn.acme.test/logo.png')
            ->assertJsonPath('branding.icon_dark', 'https://cdn.acme.test/icon.png')
            ->assertJsonPath('branding.favicon', 'https://cdn.acme.test/fav.ico')
            ->assertJsonPath('branding.primary_color', '#35b87a')
            ->assertJsonPath('branding.tag_line', 'Hosting made easy')
            ->assertJsonPath('branding.links.privacy_policy', 'https://acme.test/privacy')
            ->assertJsonPath('branding.links.terms', 'https://acme.test/terms')
            ->assertJsonPath('branding.display_message', 'Maintenance on Sunday');
    });

    it('keeps the panel\'s own value wherever Central has none', function () {
        // What the real Central answered on 2026-10-20: no logos set.
        centralSays(['name' => 'ServerAvatar', 'logo_url' => null, 'icon_url' => null, 'favicon_url' => null, 'color_code' => '35b87a']);

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('branding.source', 'central')
            ->assertJsonPath('branding.logo', '/brand/serveravatar-horizontal-light.svg')
            ->assertJsonPath('branding.logo_dark', '/brand/serveravatar-horizontal-dark.svg')
            ->assertJsonPath('branding.favicon', '/brand/serveravatar-favicon.svg')
            ->assertJsonPath('branding.links', null);
    });

    it('refuses a link or colour that is not one', function () {
        centralSays(['name' => '  ', 'logo_url' => 'javascript:alert(1)', 'favicon_url' => 'ftp://x.test/f.ico', 'color_code' => 'red; background:url(x)', 'terms_link' => 'data:text/html,hi']);

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('branding.name', 'ServerAvatar')
            ->assertJsonPath('branding.logo', '/brand/serveravatar-horizontal-light.svg')
            ->assertJsonPath('branding.favicon', '/brand/serveravatar-favicon.svg')
            ->assertJsonPath('branding.primary_color', '#35B87A')
            ->assertJsonPath('branding.links', null);
    });

    it('falls back to the panel\'s branding when Central is down, and does not ask on every load', function () {
        Http::fake(['central.test/*' => Http::response('down', 503)]);

        $this->getJson('/api/branding')->assertOk()
            ->assertJsonPath('branding.source', 'panel')
            ->assertJsonPath('branding.name', 'ServerAvatar');
        $this->getJson('/api/branding')->assertOk();

        Http::assertSentCount(1);
    });

    it('asks Central once an hour, not on every load', function () {
        centralSays(['name' => 'Acme Hosting']);

        $this->getJson('/api/branding')->assertJsonPath('branding.name', 'Acme Hosting');
        $this->getJson('/api/branding')->assertJsonPath('branding.name', 'Acme Hosting');

        Http::assertSentCount(1);
    });
});

it('never calls Central when the panel has no Central address', function () {
    config(['central.url' => '']);
    Http::fake();

    $this->getJson('/api/branding')->assertOk()->assertJsonPath('branding.source', 'panel');

    Http::assertNothingSent();
});
