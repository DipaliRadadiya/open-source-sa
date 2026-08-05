<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;

/**
 * Whole-site HTTP Basic Auth: one toggle, one username, one password, on its
 * own `app_security` permission and its own screen — unlike enable/disable,
 * which stayed on `application,manage` because it lives on the Dashboard.
 * What matters here: the credential is never returned or logged, the vhost
 * swap is reversible the same way disable/enable's is, and a failed config
 * test never leaves the site half-protected.
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

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

function securityHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** @param bool $testPasses whether `nginx -t` succeeds. */
function fakeSecurityWebServer(bool $testPasses = true): void
{
    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

function securityUrl(): string
{
    return '/api/applications/'.test()->application->id.'/security';
}

it('enables protection, hashes the password, and writes the credentials file', function () {
    fakeSecurityWebServer();

    $this->withHeaders(securityHeaders())
        ->putJson(securityUrl(), ['enabled' => true, 'username' => 'preview', 'password' => 'correct-horse'])
        ->assertOk()
        ->assertJsonPath('application.basic_auth_enabled', true)
        ->assertJsonPath('application.basic_auth_username', 'preview');

    $fresh = $this->application->fresh();

    expect($fresh->basic_auth_enabled)->toBeTrue()
        ->and($fresh->basic_auth_username)->toBe('preview')
        ->and(Hash::check('correct-horse', $fresh->basic_auth_password))->toBeTrue()
        ->and(ActivityLog::where('type', 'application')->where('action', 'basic_auth_enabled')->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'tee'
        && str_ends_with($p->command[1] ?? '', '.panel/.htpasswd'));

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'nginx' && ($p->command[1] ?? '') === '-t');
});

it('never returns the password in the response', function () {
    fakeSecurityWebServer();

    $response = $this->withHeaders(securityHeaders())
        ->putJson(securityUrl(), ['enabled' => true, 'username' => 'preview', 'password' => 'correct-horse'])
        ->assertOk();

    expect(json_encode($response->json()))->not->toContain('correct-horse');
});

it('disables protection and removes the credentials file', function () {
    fakeSecurityWebServer();
    $this->application->forceFill([
        'basic_auth_enabled' => true,
        'basic_auth_username' => 'preview',
        'basic_auth_password' => Hash::make('correct-horse'),
    ])->save();

    $this->withHeaders(securityHeaders())
        ->putJson(securityUrl(), ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('application.basic_auth_enabled', false)
        ->assertJsonPath('application.basic_auth_username', null);

    expect($this->application->fresh()->basic_auth_enabled)->toBeFalse()
        ->and(ActivityLog::where('type', 'application')->where('action', 'basic_auth_disabled')->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'rm'
        && in_array('-f', $p->command, true)
        && str_ends_with($p->command[array_key_last($p->command)] ?? '', '.panel/.htpasswd'));
});

it('requires username and password when enabling', function () {
    $this->withHeaders(securityHeaders())
        ->putJson(securityUrl(), ['enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username', 'password']);
});

it('rejects a username containing a colon', function () {
    $this->withHeaders(securityHeaders())
        ->putJson(securityUrl(), ['enabled' => true, 'username' => 'bad:name', 'password' => 'correct-horse'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);
});

it('restores the previous state before failing when the config test fails', function () {
    fakeSecurityWebServer(testPasses: false);

    $this->withHeaders(securityHeaders())
        ->putJson(securityUrl(), ['enabled' => true, 'username' => 'preview', 'password' => 'correct-horse'])
        ->assertStatus(500);

    expect($this->application->fresh()->basic_auth_enabled)->toBeFalse()
        ->and(ActivityLog::where('action', 'basic_auth_enabled')->exists())->toBeFalse();
});

it('refuses without manage permission', function () {
    fakeSecurityWebServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_security', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(securityUrl(), ['enabled' => true, 'username' => 'preview', 'password' => 'correct-horse'])
        ->assertStatus(403);
});
