<?php

use App\Enums\WafMode;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationWafRule;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationLogManager;
use App\Services\Server\Applications\Waf8GManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

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

    // The vhost is named after the slug, not the domain — a domain is mutable
    // and was never unique, so two sites could claim one and overwrite each
    // other's config. Matching on 'shop.test' found nothing, and the null went
    // straight into an array offset.
    $vhostWrite = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/shop.conf'));
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

    // The vhost is named after the slug, not the domain — a domain is mutable
    // and was never unique, so two sites could claim one and overwrite each
    // other's config. Matching on 'shop.test' found nothing, and the null went
    // straight into an array offset.
    $vhostWrite = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/shop.conf'));

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

    // The vhost is named after the slug, not the domain — a domain is mutable
    // and was never unique, so two sites could claim one and overwrite each
    // other's config. Matching on 'shop.test' found nothing, and the null went
    // straight into an array offset.
    $vhostWrite = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/shop.conf'));

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

it('describes every category, not just names it', function () {
    $response = $this->withHeaders(wafHeaders())->getJson('/api/waf-options')->assertOk();

    foreach ($response->json('waf_categories') as $category) {
        // "Bad cookies" is not enough to decide whether switching a category
        // off is safe, and switching one off to fix a false positive is the
        // documented normal use of this screen.
        expect($category['description'])->not->toBeEmpty()
            ->and($category['description'])->not->toBe($category['title']);
    }
});

it('leaves the stored categories alone when the request does not mention them', function () {
    fakeWafWebServer();

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true,
        'mode' => 'enforce',
        'categories' => ['request_uri'],
    ])->assertOk();

    // Changing only the mode must not silently switch the other five back on
    // — including the one the user turned off to fix a false positive.
    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true,
        'mode' => 'detect',
    ])->assertOk();

    expect($this->application->fresh()->waf_categories)->toBe(['request_uri']);
});

it('switches every category off when an empty list is sent', function () {
    fakeWafWebServer();

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true,
        'mode' => 'enforce',
        'categories' => [],
    ])->assertOk();

    // Absent and empty are two different intentions and must not collapse.
    expect($this->application->fresh()->waf_categories)->toBe([]);
});

it('leaves the stored rules alone when the request does not mention them', function () {
    fakeWafWebServer();

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true,
        'mode' => 'enforce',
        'exceptions' => ['mobiquo'],
        'custom_rules' => ['evilbot'],
    ])->assertOk();

    $before = ApplicationWafRule::orderBy('id')->pluck('id')->all();

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true,
        'mode' => 'detect',
    ])->assertOk();

    // Same rows, not rewritten ones: re-creating them would reset their
    // timestamps and make "when did this rule appear" unanswerable.
    expect(ApplicationWafRule::orderBy('id')->pluck('id')->all())->toBe($before);
});

it('offers the detect log only while the firewall is watching', function () {
    fakeWafWebServer();

    $keys = fn () => collect(
        $this->withHeaders(wafHeaders())
            ->getJson('/api/applications/'.$this->application->id.'/logs')
            ->json('logs')
    )->pluck('key')->all();

    // Off: nothing writes it.
    expect($keys())->not->toContain('waf_detect');

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true, 'mode' => 'detect',
    ])->assertOk();

    // Watching: this is the whole point of detect mode, and until now the
    // evidence it produced was unreachable from the panel.
    expect($keys())->toContain('waf_detect');

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true, 'mode' => 'enforce',
    ])->assertOk();

    // Enforcing returns 403 and writes nothing here, so listing it would show
    // an empty file that reads as broken rather than as "not this mode".
    expect($keys())->not->toContain('waf_detect');
});

it('reads the detect log from the file the vhost was told to write', function () {
    $written = [];
    fakeWafWebServer(onWrite: function (array $write) use (&$written) {
        $written[] = $write;
    });

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true, 'mode' => 'detect',
    ])->assertOk();

    // Where nginx was actually configured to log, read back out of the vhost
    // that was written — not recomputed here, which would only restate the
    // assumption instead of testing it.
    $vhost = collect($written)->pluck('input')->first(
        fn (string $body): bool => str_contains($body, 'waf-detect.log'),
    );

    preg_match('#access_log (\S*waf-detect\.log)#', (string) $vhost, $matches);
    $writerPath = $matches[1] ?? null;

    // Where the panel looks when the user opens the log.
    $readerPath = app(ApplicationLogManager::class)
        ->find($this->application->fresh(), 'waf_detect')['path'] ?? null;

    // These disagreed: the vhost wrote to `panelPath()` while the catalog read
    // `documentRoot()/.panel`, so detect mode showed an empty file however much
    // it had matched — and an empty detect log reads as "nothing would be
    // blocked", which invites enforcing a ruleset nobody has checked.
    //
    // The existing test above asserts only that the KEY is offered, which is
    // exactly why this survived. Comparing the two real sources is the check
    // that would have caught it.
    expect($writerPath)->not->toBeNull()
        ->and($readerPath)->toBe($writerPath);
});

it('hides the firewall entirely on a web server that cannot enforce it', function () {
    fakeWafWebServer();

    // One server runs one web server, so this is a server-wide fact.
    ServerCapability::query()->update(['web_server' => 'openlitespeed', 'stack' => 'ols']);

    // Not in the application's feature list, so it is not in the sidebar the
    // panel builds from permissions — hidden rather than shown-and-refused,
    // because there is nothing the user could do here to turn it on.
    expect($this->application->fresh()->features())->not->toContain('app_firewall');

    // And CheckPermission 404s the routes off the back of the same list, so
    // the endpoint does not exist on this server rather than existing and
    // saying no. Previously this answered 200 and stored waf_enabled: true
    // while no OLS template references the rules — a green firewall
    // inspecting nothing.
    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'enforce'])
        ->assertNotFound();

    expect($this->application->fresh()->waf_enabled)->toBeFalse();
});

it('keeps the firewall visible when the web server is not yet known', function () {
    fakeWafWebServer();

    // A freshly provisioned box has no capability row yet, and features() runs
    // on every sidebar and every application route. Hiding a working screen
    // because the web server is momentarily unknown is worse than showing one
    // the manager would refuse — so this fails open.
    ServerCapability::query()->delete();

    expect($this->application->fresh()->features())->toContain('app_firewall');
});

it('still lets an unsupported web server switch the firewall off', function () {
    fakeWafWebServer();

    ServerCapability::query()->update(['web_server' => 'openlitespeed', 'stack' => 'ols']);

    // Asserted against the guard rather than through the HTTP stack, because
    // rendering an OLS vhost needs a real shared config this fake has no way
    // to provide — and the guard is the part being tested. A site enabled
    // before this guard existed must stay recoverable: refusing every write
    // would strand it permanently on.
    $refuse = fn (bool $enabled): bool => rescue(
        function () use ($enabled): bool {
            app(Waf8GManager::class)->apply($this->application, $enabled, WafMode::Enforce);

            return false;
        },
        fn (Throwable $e): bool => $e instanceof ValidationException,
        false,
    );

    expect($refuse(true))->toBeTrue()
        ->and($refuse(false))->toBeFalse();
});
