<?php

use App\Models\User;

it('reports registration open when no users exist', function () {
    $response = $this->getJson('/api/basic-info');

    $response->assertOk()
        ->assertJsonPath('basic_info.registration_open', true)
        ->assertJsonStructure(['basic_info' => ['registration_open', 'app_version', 'locales_available', 'cookie_auth_enabled']]);
});

it('reports registration closed once a user exists', function () {
    User::factory()->admin()->create();

    $response = $this->getJson('/api/basic-info');

    $response->assertOk()->assertJsonPath('basic_info.registration_open', false);
});

it('reports the same version as /health (bug #51)', function () {
    $health = $this->getJson('/api/health')->assertOk()->json('health.version');

    expect($this->getJson('/api/basic-info')->json('basic_info.app_version'))->toBe($health);
});
