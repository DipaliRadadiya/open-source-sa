<?php

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
