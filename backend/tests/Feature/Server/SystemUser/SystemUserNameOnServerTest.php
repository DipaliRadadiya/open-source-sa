<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * A new system user's name that is already a group on the server (RC-27,
 * 2026-10-22). useradd makes a group of the same name, so `docker` — a group
 * with no user — failed with "group docker exists": a 201 on the application
 * form and a provisioning job that died, because only `getent passwd` was
 * asked. Now both are asked, before anything is written.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    Queue::fake();

    // `plugdev` is a group and not a user, as on a stock Ubuntu box; every
    // other name is free. Everything else succeeds.
    Process::fake(function ($process) {
        $command = (array) $process->command;
        $at = array_search('getent', $command, true);

        if ($at === false) {
            return Process::result();
        }

        [$database, $name] = [$command[$at + 1] ?? '', $command[$at + 2] ?? ''];

        return $database === 'group' && $name === 'plugdev'
            ? Process::result(output: "plugdev:x:46:\n")
            : Process::result(exitCode: 2);
    });
});

it('refuses a system user named like an existing group, before useradd runs', function () {
    $this->actingAs($this->admin)->postJson('/api/system-users', ['username' => 'plugdev'])
        ->assertStatus(422)
        ->assertJsonPath('errors.username.0', __('errors/system-user.username_taken_on_server'));

    expect(SystemUser::where('username', 'plugdev')->exists())->toBeFalse();
    Process::assertNotRan(fn ($process) => in_array('useradd', (array) $process->command, true));
});

it('still creates a system user whose name is free as both', function () {
    $this->actingAs($this->admin)->postJson('/api/system-users', ['username' => 'deployer'])->assertCreated();

    Process::assertRan(fn ($process) => in_array('useradd', (array) $process->command, true));
});

it('refuses the same name chosen on the application form with a 422, not a failed job', function () {
    $this->actingAs($this->admin)->postJson('/api/applications', [
        'name' => 'Plug Site', 'domain' => 'plug.example.com', 'site_type' => 'static',
        'generate_system_user' => true, 'system_user' => ['username' => 'plugdev'],
    ])->assertStatus(422)->assertJsonPath('errors', fn (array $errors) => array_key_exists('system_user.username', $errors));

    expect(Application::count())->toBe(0);
});

it('never generates a name that is already a group', function () {
    $response = $this->actingAs($this->admin)->postJson('/api/applications', [
        'name' => 'plugdev', 'domain' => 'plugdev.example.com', 'site_type' => 'static', 'generate_system_user' => true,
    ])->assertCreated();

    expect(Application::find($response->json('application.id'))->systemUser->username)
        ->not->toBe('plugdev')->toStartWith('plugdev');
});

it('refuses the panel\'s own and other system group names outright, without asking the server', function (string $name) {
    $this->actingAs($this->admin)->postJson('/api/system-users', ['username' => $name])
        ->assertStatus(422)
        ->assertJsonPath('errors.username.0', __('errors/system-user.reserved_username'));

    Process::assertNothingRan();
})->with(['panel', 'docker', 'sudo', 'adm', 'lxd', 'ssh-users', 'sv-app-shell', 'systemd-journal']);
