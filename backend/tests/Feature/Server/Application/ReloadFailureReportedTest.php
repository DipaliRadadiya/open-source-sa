<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * A config that passed `nginx -t` and then failed to reload is not live. Five
 * callers ran the reload and answered 200 whatever it said (2026-11-07 review;
 * only ApplyVhost checked). They now save what is on disk and say the web
 * server did not reload, instead of reporting a change the server is not
 * serving.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active',
        'web_root' => '/', 'php_version' => '8.4',
    ]);
});

/**
 * nginx -t passes; `systemctl reload nginx` answers $reloadExit.
 *
 * @return ArrayObject<int, list<string>> every command that ran
 */
function fakeReloadingServer(int $reloadExit): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($reloadExit, $ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran->append($args);

        if (($args[0] ?? '') === 'systemctl' && ($args[1] ?? '') === 'reload') {
            return Process::result(exitCode: $reloadExit, errorOutput: $reloadExit === 0 ? '' : 'Job for nginx.service failed');
        }

        // Only the web root's own symlink check: nothing here is a link.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-L') {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function reloadHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function appUrl(string $path): string
{
    return '/api/applications/'.test()->application->id.$path;
}

it('says the web server did not reload after a web root change, and keeps what is on disk', function () {
    fakeReloadingServer(1);

    $this->withHeaders(reloadHeaders())->putJson(appUrl('/web-root'), ['web_root' => 'public'])
        ->assertStatus(500)
        ->assertJsonPath('code', 'web_server_reload_failed')
        ->assertJsonPath('message', __('errors/web-server.reload_failed'));

    expect($this->application->fresh()->web_root)->toBe('public');
});

it('still answers 200 for a web root change when the reload worked', function () {
    fakeReloadingServer(0);

    $this->withHeaders(reloadHeaders())->putJson(appUrl('/web-root'), ['web_root' => 'public'])->assertOk();
});

it('says the web server did not reload after a firewall change', function () {
    fakeReloadingServer(1);

    $this->withHeaders(reloadHeaders())
        ->putJson(appUrl('/waf'), ['enabled' => true, 'mode' => 'detect', 'categories' => ['query_string'], 'custom_rules' => ['bad-path']])
        ->assertStatus(500)
        ->assertJsonPath('code', 'web_server_reload_failed');

    $application = $this->application->fresh('wafRules');

    // The row and its rules say what the tested file on disk says.
    expect($application->waf_enabled)->toBeTrue()
        ->and($application->wafRules->pluck('value')->all())->toContain('bad-path');
});

it('says the web server did not reload after a bot blocker change', function () {
    fakeReloadingServer(1);

    $this->withHeaders(reloadHeaders())->putJson(appUrl('/bot-blocker'), ['policy' => 'block_training'])
        ->assertStatus(500)
        ->assertJsonPath('code', 'web_server_reload_failed');

    expect($this->application->fresh()->ai_bot_policy?->value)->toBe('block_training');
});

it('says the web server did not reload after turning password protection on', function () {
    fakeReloadingServer(1);

    $this->withHeaders(reloadHeaders())->putJson(appUrl('/security'), [
        'enabled' => true, 'username' => 'admin', 'password' => 'a-long-enough-password',
    ])->assertStatus(500)->assertJsonPath('code', 'web_server_reload_failed');

    expect($this->application->fresh()->basic_auth_enabled)->toBeTrue();
});

it('keeps the credential file when the reload after turning protection off failed', function () {
    // The web server is still on the config that points at the file; removing
    // it now would break the site until the next reload.
    $this->application->forceFill([
        'basic_auth_enabled' => true, 'basic_auth_username' => 'admin', 'basic_auth_password' => 'hash',
    ])->save();

    $ran = fakeReloadingServer(1);

    $this->withHeaders(reloadHeaders())->putJson(appUrl('/security'), ['enabled' => false])
        ->assertStatus(500)
        ->assertJsonPath('code', 'web_server_reload_failed');

    expect($this->application->fresh()->basic_auth_enabled)->toBeFalse()
        ->and(collect($ran)->contains(fn (array $a) => ($a[0] ?? '') === 'rm' && str_contains(implode(' ', $a), 'htpasswd')))->toBeFalse();
});

it('fails a deploy whose new config never went live', function () {
    fakeReloadingServer(1);

    $republish = new ReflectionMethod(GitDeployer::class, 'republishVhost');

    expect(fn () => $republish->invoke(app(GitDeployer::class), $this->application, '/home/siteowner/shop/public_html'))
        ->toThrow(fn (ProvisioningFailedException $e) => expect($e->step)->toBe('reload'));
});

it('has the message translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        expect(__('errors/web-server.reload_failed', [], $locale))->not->toBe('errors/web-server.reload_failed');
    }
});
