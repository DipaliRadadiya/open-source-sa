<?php

use App\Models\Application;
use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create(['system_user_id' => $this->systemUser->id]);
});

it('can assign a cronjob to an application', function () {
    $cronjob = Cronjob::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => $this->application->id,
    ]);

    expect($cronjob->application->is($this->application))->toBeTrue()
        ->and($cronjob->application_id)->toBe($this->application->id);
});

it('can leave a cronjob without an application (server-level)', function () {
    $cronjob = Cronjob::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => null,
    ]);

    expect($cronjob->application)->toBeNull()
        ->and($cronjob->application_id)->toBeNull();
});

it('filters cronjobs by application_id', function () {
    $otherApp = Application::factory()->create(['system_user_id' => $this->systemUser->id]);

    $appCronjob = Cronjob::factory()->count(2)->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => $this->application->id,
    ]);

    $otherAppCronjob = Cronjob::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => $otherApp->id,
    ]);

    $serverLevel = Cronjob::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => null,
    ]);

    $appCronjobs = Cronjob::query()
        ->where('application_id', $this->application->id)
        ->get();

    expect($appCronjobs)->toHaveCount(2)
        ->and($appCronjobs->pluck('id')->all())->toContain($appCronjob[0]->id, $appCronjob[1]->id)
        ->and($appCronjobs->pluck('id'))->not->toContain($otherAppCronjob->id, $serverLevel->id);
});

it('deleting an application nullifies its cronjobs', function () {
    $cronjob = Cronjob::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => $this->application->id,
    ]);

    $this->application->delete();

    $cronjob->refresh();
    expect($cronjob->application_id)->toBeNull();
});

it('application has many cronjobs', function () {
    $cronjobs = Cronjob::factory()->count(3)->create([
        'system_user_id' => $this->systemUser->id,
        'application_id' => $this->application->id,
    ]);

    expect($this->application->cronjobs)->toHaveCount(3);
});

it('application can have zero cronjobs', function () {
    expect($this->application->cronjobs)->toHaveCount(0);
});
