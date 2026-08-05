<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Per-application fail2ban: a different feature from the server-level one
 * — this watches one site's own access log. What matters here: enabling it
 * writes a jail scoped to that site's own log path, WordPress gets a
 * second stricter jail the same toggle doesn't add for other types, and a
 * failed reload rolls the `fail2ban_enabled` column back rather than
 * leaving the database claiming a state the server never reached (there is
 * no `-t`-style dry run for fail2ban-client the way there is for nginx).
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

    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

function fail2banHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** @param  (callable(array): void)|null  $onWrite */
function fakeFail2banClient(bool $reloadPasses = true, ?callable $onWrite = null): void
{
    Process::fake(function ($process) use ($reloadPasses, $onWrite) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'tee' && $onWrite !== null) {
            $onWrite(['path' => $args[1] ?? '', 'input' => $process->input ?? '']);
        }

        if (($args[0] ?? '') === 'fail2ban-client' && ($args[1] ?? '') === 'reload') {
            return Process::result(exitCode: $reloadPasses ? 0 : 1, errorOutput: $reloadPasses ? '' : 'reload failed');
        }

        if (($args[0] ?? '') === 'fail2ban-client' && ($args[1] ?? '') === 'status') {
            return Process::result(output: "Status\n|- Number of jail:\t1\n`- Jail list:\tapp-1-generic, app-1-wplogin");
        }

        return Process::result(exitCode: 0);
    });
}

function fail2banUrl(): string
{
    return '/api/applications/'.test()->application->id.'/fail2ban';
}

it('enables fail2ban and writes a generic jail scoped to the site access log', function () {
    $this->application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.test', 'site_type' => 'php',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $writes = [];
    fakeFail2banClient(onWrite: function ($write) use (&$writes) {
        $writes[] = $write;
    });

    $this->withHeaders(fail2banHeaders())
        ->putJson(fail2banUrl(), ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('application.fail2ban_enabled', true);

    expect($this->application->fresh()->fail2ban_enabled)->toBeTrue()
        ->and(ActivityLog::where('type', 'application')->where('action', 'fail2ban_enabled')->exists())->toBeTrue();

    $configWrite = collect($writes)->first(fn ($w) => str_contains($w['path'], 'panel-apps.local'));

    expect($configWrite['input'])
        ->toContain("[app-{$this->application->id}-generic]")
        ->toContain('shop.test.access.log')
        ->not->toContain('wplogin'); // not WordPress — no second jail
});

it('adds a second stricter jail for wordpress sites', function () {
    $this->application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Blog', 'domain' => 'blog.test', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $writes = [];
    fakeFail2banClient(onWrite: function ($write) use (&$writes) {
        $writes[] = $write;
    });

    $this->withHeaders(fail2banHeaders())
        ->putJson(fail2banUrl(), ['enabled' => true])
        ->assertOk();

    $configWrite = collect($writes)->first(fn ($w) => str_contains($w['path'], 'panel-apps.local'));

    expect($configWrite['input'])
        ->toContain("[app-{$this->application->id}-generic]")
        ->toContain("[app-{$this->application->id}-wplogin]")
        ->toContain('filter = panel-app-wplogin');

    // The actual wp-login/xmlrpc pattern lives in the filter definition,
    // not the jail drop-in — confirm the shipped filter file has it.
    $filterWrite = collect($writes)->first(fn ($w) => str_contains($w['path'], 'panel-app-wplogin.conf'));
    expect($filterWrite['input'])->toContain('wp-login');
});

it('rolls back the column when the reload fails', function () {
    $this->application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.test', 'site_type' => 'php',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    fakeFail2banClient(reloadPasses: false);

    $this->withHeaders(fail2banHeaders())
        ->putJson(fail2banUrl(), ['enabled' => true])
        ->assertStatus(500);

    expect($this->application->fresh()->fail2ban_enabled)->toBeFalse()
        ->and(ActivityLog::where('action', 'fail2ban_enabled')->exists())->toBeFalse();
});

it('is a no-op when the state is unchanged', function () {
    $this->application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.test', 'site_type' => 'php',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        'fail2ban_enabled' => false,
    ]);

    fakeFail2banClient();

    $this->withHeaders(fail2banHeaders())
        ->putJson(fail2banUrl(), ['enabled' => false])
        ->assertOk();

    expect(ActivityLog::where('action', 'fail2ban_disabled')->exists())->toBeFalse();
});

it('shows live jail status', function () {
    $this->application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.test', 'site_type' => 'php',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        'fail2ban_enabled' => true,
    ]);

    fakeFail2banClient();

    $this->withHeaders(fail2banHeaders())
        ->getJson(fail2banUrl())
        ->assertOk()
        ->assertJsonPath('fail2ban_enabled', true)
        ->assertJsonCount(1, 'jails');
});

it('refuses without manage permission', function () {
    $this->application = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.test', 'site_type' => 'php',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    fakeFail2banClient();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_fail2ban', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(fail2banUrl(), ['enabled' => true])
        ->assertStatus(403);
});
