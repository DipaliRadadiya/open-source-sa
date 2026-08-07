<?php

namespace Tests\Feature\Server;

use App\Models\Application;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(SystemUser::factory()->admin()->make());

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
    ]);
});

it('exposes directory_size_bytes in application resource when set', function () {
    $this->application->update(['directory_size_bytes' => 1024 * 1024 * 50]);

    $response = $this->getJson("/api/applications/{$this->application->id}");

    $response->assertOk()
        ->assertJsonPath('application.directory_size_bytes', 1024 * 1024 * 50);
});

it('does not include directory_size_bytes in resource when null', function () {
    $this->application->update(['directory_size_bytes' => null]);

    $response = $this->getJson("/api/applications/{$this->application->id}");

    $response->assertOk()
        ->assertJsonMissing(['directory_size_bytes' => null]);
});
