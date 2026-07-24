<?php

it('returns the default branding info without authentication', function () {
    $response = $this->getJson('/api/branding');

    $response->assertOk()
        ->assertJsonPath('branding.name', 'ServerAvatar')
        ->assertJsonPath('branding.logo', 'https://app.serveravatar.com/logo/SaLogoDark.png')
        ->assertJsonPath('branding.logo_dark', 'https://app.serveravatar.com/logo/dark-logo.png')
        ->assertJsonPath('branding.icon', 'https://app.serveravatar.com/logo/logo-sm.png')
        ->assertJsonPath('branding.icon_dark', 'https://app.serveravatar.com/logo/dark-logo-sm.png')
        ->assertJsonPath('branding.favicon', 'https://app.serveravatar.com/logo/logo-sm.png')
        ->assertJsonPath('branding.primary_color', '#076aff');
});

it('reflects branding overrides from config', function () {
    config(['branding.name' => 'Custom Brand', 'branding.primary_color' => '#ff0000']);

    $response = $this->getJson('/api/branding');

    $response->assertOk()
        ->assertJsonPath('branding.name', 'Custom Brand')
        ->assertJsonPath('branding.primary_color', '#ff0000');
});
