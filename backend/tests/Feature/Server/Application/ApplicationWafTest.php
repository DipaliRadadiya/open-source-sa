<?php

use App\Enums\WafMode;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationWafRule;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The 8G Firewall: six independently switchable categories, detect vs
 * enforce, and a per-app exceptions/custom-rules list. What matters here:
 * disabling a category actually removes it from the rendered vhost (not
 * just from the UI), the shared nginx maps file is written once per apply,
 * a failed config test rolls back both the columns and the child-table
 * rules together, and rules are never persisted until the test passes.
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

function wafHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/**
 * @param  bool  $testPasses  whether `nginx -t` succeeds.
 * @param  (callable(array): void)|null  $onWrite  inspect every `tee` write.
 */
function fakeWafWebServer(bool $testPasses = true, ?callable $onWrite = null): void
{
    Process::fake(function ($process) use ($testPasses, $onWrite) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'tee' && $onWrite !== null) {
            $onWrite(['path' => $args[1] ?? '', 'input' => $process->input ?? '']);
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

function wafUrl(): string
{
    return '/api/applications/'.test()->application->id.'/waf';
}

it('lists the six categories and two modes', function () {
    $this->withHeaders(wafHeaders())
        ->getJson('/api/waf-options')
        ->assertOk()
        ->assertJsonCount(6, 'waf_categories')
        ->assertJsonCount(2, 'waf_modes');
});

it('enables the firewall, writes the shared nginx maps file, and persists the rules', function () {
    $writes = [];
    fakeWafWebServer(onWrite: function ($write) use (&$writes) {
        $writes[] = $write;
    });

    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), [
            'enabled' => true,
            'mode' => 'enforce',
            'categories' => ['query_string', 'request_uri'],
            'exceptions' => ['mobiquo'],
            'custom_rules' => ['bad-path'],
        ])
        ->assertOk()
        ->assertJsonPath('application.waf_enabled', true)
        ->assertJsonPath('application.waf_mode', 'enforce')
        ->assertJsonPath('application.waf_categories', ['query_string', 'request_uri']);

    $fresh = $this->application->fresh();

    expect($fresh->waf_enabled)->toBeTrue()
        ->and($fresh->waf_mode)->toBe(WafMode::Enforce)
        ->and($fresh->waf_categories)->toBe(['query_string', 'request_uri'])
        ->and(ApplicationWafRule::where('type', 'exception')->where('value', 'mobiquo')->exists())->toBeTrue()
        ->and(ApplicationWafRule::where('type', 'block')->where('value', 'bad-path')->exists())->toBeTrue()
        ->and(ActivityLog::where('type', 'application')->where('action', 'waf_updated')->exists())->toBeTrue();

    $sharedMapsWrite = collect($writes)->firstWhere('path', config('server.waf.nginx_maps_path'));
    expect($sharedMapsWrite)->not->toBeNull()
        ->and($sharedMapsWrite['input'])->toContain('map $query_string $bad_querystring_ng');

    $vhostWrite = collect($writes)->first(fn ($w) => str_contains($w['path'], 'shop.test'));
    expect($vhostWrite['input'])
        ->toContain('bad_querystring_ng')
        ->toContain('bad_request_ng')
        ->not->toContain('bad_bot_ng'); // user_agent category not selected
});

it('excludes disabled categories from the rendered vhost', function () {
    $writes = [];
    fakeWafWebServer(onWrite: function ($write) use (&$writes) {
        $writes[] = $write;
    });

    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), [
            'enabled' => true,
            'mode' => 'enforce',
            'categories' => ['method'],
        ])
        ->assertOk();

    $vhostWrite = collect($writes)->first(fn ($w) => str_contains($w['path'], 'shop.test'));

    expect($vhostWrite['input'])
        ->toContain('not_allowed_method_ng')
        ->not->toContain('bad_querystring_ng')
        ->not->toContain('bad_request_ng');
});

it('logs would-be blocks instead of enforcing in detect mode', function () {
    $writes = [];
    fakeWafWebServer(onWrite: function ($write) use (&$writes) {
        $writes[] = $write;
    });

    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'detect', 'categories' => ['query_string']])
        ->assertOk()
        ->assertJsonPath('application.waf_mode', 'detect');

    $vhostWrite = collect($writes)->first(fn ($w) => str_contains($w['path'], 'shop.test'));

    expect($vhostWrite['input'])
        ->not->toContain('return 403')
        ->toContain('access_log')
        ->toContain('waf-detect.log');
});

it('restores the previous state and keeps existing rules when the config test fails', function () {
    ApplicationWafRule::create(['application_id' => $this->application->id, 'type' => 'exception', 'value' => 'old-exception']);
    $this->application->forceFill(['waf_enabled' => true, 'waf_mode' => 'enforce', 'waf_categories' => ['method']])->save();

    fakeWafWebServer(testPasses: false);

    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'enforce', 'categories' => ['query_string'], 'exceptions' => ['new-exception']])
        ->assertStatus(500);

    $fresh = $this->application->fresh();

    expect($fresh->waf_categories)->toBe(['method'])
        ->and(ApplicationWafRule::where('value', 'old-exception')->exists())->toBeTrue()
        ->and(ApplicationWafRule::where('value', 'new-exception')->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'waf_updated')->exists())->toBeFalse();
});

it('shows the current state including persisted rules', function () {
    $this->application->forceFill(['waf_enabled' => true, 'waf_mode' => 'detect', 'waf_categories' => ['cookie']])->save();
    ApplicationWafRule::create(['application_id' => $this->application->id, 'type' => 'exception', 'value' => 'known-good']);

    $this->withHeaders(wafHeaders())
        ->getJson(wafUrl())
        ->assertOk()
        ->assertJsonPath('application.waf_enabled', true)
        ->assertJsonPath('application.waf_categories', ['cookie'])
        ->assertJsonPath('application.waf_exceptions', ['known-good']);
});

it('rejects an unknown mode or category', function () {
    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'block-everything', 'categories' => ['not_a_category']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mode', 'categories.0']);
});

it('refuses without manage permission', function () {
    fakeWafWebServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_firewall', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'enforce', 'categories' => ['method']])
        ->assertStatus(403);
});
