<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Enable/disable is a Dashboard action, not a separate screen or permission —
 * it stays on `application,manage`. What matters here: the vhost swap is
 * reversible, a failed config test never leaves a site pointed nowhere, and
 * the state cannot drift (disabling twice, enabling a site that is not
 * disabled).
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

function availabilityHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** @param bool $testPasses whether `nginx -t` succeeds. */
function fakeWebServer(bool $testPasses = true): void
{
    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

function disableUrl(): string
{
    return '/api/applications/'.test()->application->id.'/disable';
}

function enableUrl(): string
{
    return '/api/applications/'.test()->application->id.'/enable';
}

it('swaps the vhost to the unavailable page and marks the site disabled', function () {
    fakeWebServer();

    $this->withHeaders(availabilityHeaders())
        ->postJson(disableUrl())
        ->assertOk()
        ->assertJsonPath('application.is_disabled', true);

    expect($this->application->fresh()->disabled_at)->not->toBeNull()
        ->and(ActivityLog::where('type', 'application')->where('action', 'disabled')->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'nginx' && ($p->command[1] ?? '') === '-t');
});

it('refuses to disable a site that is already disabled', function () {
    fakeWebServer();
    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(availabilityHeaders())
        ->postJson(disableUrl())
        ->assertStatus(422);
});

it('restores the real vhost before failing when the config test fails, and never marks the site disabled', function () {
    fakeWebServer(testPasses: false);

    $this->withHeaders(availabilityHeaders())
        ->postJson(disableUrl())
        ->assertStatus(500);

    expect($this->application->fresh()->disabled_at)->toBeNull()
        ->and(ActivityLog::where('action', 'disabled')->exists())->toBeFalse();
});

it('restores the real vhost and clears the disabled state', function () {
    fakeWebServer();
    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(availabilityHeaders())
        ->postJson(enableUrl())
        ->assertOk()
        ->assertJsonPath('application.is_disabled', false);

    expect($this->application->fresh()->disabled_at)->toBeNull()
        ->and(ActivityLog::where('type', 'application')->where('action', 'enabled')->exists())->toBeTrue();
});

it('refuses to enable a site that is not disabled', function () {
    fakeWebServer();

    $this->withHeaders(availabilityHeaders())
        ->postJson(enableUrl())
        ->assertStatus(422);
});

it('leaves the site disabled when re-enabling fails its config test', function () {
    fakeWebServer(testPasses: false);
    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(availabilityHeaders())
        ->postJson(enableUrl())
        ->assertStatus(500);

    expect($this->application->fresh()->disabled_at)->not->toBeNull();
});

it('refuses without manage permission', function () {
    fakeWebServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'application', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson(disableUrl())
        ->assertStatus(403);
});
