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
use App\Services\Server\Waf\OlsWafRuleset;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;
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

    // Still listed after enforcing. The flow is detect → read this → add
    // exceptions → enforce, so hiding it here removed the evidence at the
    // moment someone acted on it, and GET /logs/{key} 404'd with it.
    expect($keys())->toContain('waf_detect');

    // Off entirely: nothing writes it and nothing claims to.
    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => false, 'mode' => 'enforce',
    ])->assertOk();

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
        // `access_log`: the logrotate policy names the file too (LOG-01).
        fn (string $body): bool => str_contains($body, 'access_log') && str_contains($body, 'waf-detect.log'),
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

/**
 * A web server that cannot enforce the WAF. None of the three can any more —
 * OpenLiteSpeed gained it on 2026-09-30 — but the guard stays for a fourth,
 * so it is tested against a stand-in rather than dropped.
 */
function webServerWithoutWaf(): void
{
    ServerCapability::query()->update(['web_server' => 'openlitespeed', 'stack' => 'ols']);

    $driver = Mockery::mock(OlsDriver::class)->makePartial();
    $driver->shouldReceive('supportsWaf')->andReturn(false);
    app()->instance(OlsDriver::class, $driver);
}

it('hides the firewall entirely on a web server that cannot enforce it', function () {
    fakeWafWebServer();

    // One server runs one web server, so this is a server-wide fact.
    webServerWithoutWaf();

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

    webServerWithoutWaf();

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

describe('exceptions and custom rules as written into the config', function () {
    /**
     * Render the site's vhost with these rules and return it.
     *
     * @param  array<int, string>  $exceptions
     * @param  array<int, string>  $custom
     */
    function wafVhost(string $driver, array $exceptions, array $custom = ['qa-block']): string
    {
        $app = test()->application;
        $app->forceFill(['waf_enabled' => true, 'waf_mode' => 'enforce'])->save();
        $app->setRelation('wafRules', collect([
            ...array_map(fn ($v) => new ApplicationWafRule(['type' => 'exception', 'value' => $v]), $exceptions),
            ...array_map(fn ($v) => new ApplicationWafRule(['type' => 'block', 'value' => $v]), $custom),
        ]));

        return app($driver)->renderConfig($app->load('systemUser'), '/home/siteowner/shop/public_html');
    }

    /**
     * What nginx's parser does to a double-quoted string, then the regex
     * nginx would compile — so the test checks the match, not the text.
     */
    function nginxMatches(string $quoted, string $subject): bool
    {
        $unescaped = preg_replace_callback('/\\\\(.)/s', fn ($m) => in_array($m[1], ['"', "'", '\\'], true) ? $m[1] : '\\'.$m[1], $quoted);

        return preg_match('/'.str_replace('/', '\/', $unescaped).'/i', $subject) === 1;
    }

    /** The pattern as the expression engine gets it (already unescaped by the caller). */
    function apacheMatches(string $pattern, string $subject): bool
    {
        return preg_match('#'.$pattern.'#i', $subject) === 1;
    }

    it('reaches the config unencoded, so & \' " match on nginx', function () {
        $value = 'page=1&x="it\'s"';
        $config = wafVhost(NginxDriver::class, [$value]);

        // HTML-encoded, `&` became `&amp;` and the exception never matched.
        expect($config)->not->toContain('&amp;')->not->toContain('&quot;')->not->toContain('&#039;');

        preg_match('/if \(\$uri ~\* "((?:[^"\\\\]|\\\\.)*)"\) \{ set \$waf_exception "1"; \}/', $config, $m);

        expect($m)->not->toBeEmpty()
            ->and(nginxMatches($m[1], 'a=b&'.$value))->toBeTrue()
            ->and(nginxMatches($m[1], 'page=1'))->toBeFalse();
    });

    it('matches the text literally on nginx, backslashes and regex symbols included', function (string $value, string $hit, string $miss) {
        $config = wafVhost(NginxDriver::class, [$value]);
        preg_match('/if \(\$uri ~\* "((?:[^"\\\\]|\\\\.)*)"\) \{ set \$waf_exception "1"; \}/', $config, $m);

        expect(nginxMatches($m[1], $hit))->toBeTrue()
            ->and(nginxMatches($m[1], $miss))->toBeFalse();
    })->with([
        'dot' => ['ab.c', 'xab.cx', 'abxc'],
        'backslash' => ['c:\\tmp', 'path=c:\\tmp', 'path=c:tmp'],
        'regex symbols' => ['(a+)?', 'q=(a+)?', 'q=aa'],
    ]);

    it('matches an exception on the path only on Apache, a block rule on the query too', function () {
        $config = wafVhost(ApacheDriver::class, ['action=upload']);

        // Bug #82: on the query string or the user agent, an exception was a
        // password anyone could type. Block rules still read the query.
        expect($config)->not->toContain('Query_String')
            // Backslashes doubled: Apache's config parser halves them.
            ->and($config)->toContain('SetEnvIfExpr "%{REQUEST_URI} =~ m#action\\\\=upload#i" waf_exception')
            ->and($config)->not->toContain('%{QUERY_STRING} =~ m#action')
            ->and($config)->toContain('SetEnvIfExpr "%{REQUEST_URI} =~ m#qa\\\\-block#i || %{QUERY_STRING} =~ m#qa\\\\-block#i" waf_custom');
    });

    it('matches the text literally on Apache too, not as a regex', function (string $value, string $hit, string $miss) {
        // As Apache's config parser hands it to the expression engine.
        $config = OlsWafRuleset::apacheUnescape(wafVhost(ApacheDriver::class, [$value]), '"');
        preg_match('/%\{REQUEST_URI\} =~ m#((?:[^#\\\\]|\\\\.)*)#i" waf_exception/', $config, $m);

        expect($m)->not->toBeEmpty()
            ->and(apacheMatches($m[1], $hit))->toBeTrue()
            ->and(apacheMatches($m[1], $miss))->toBeFalse();
    })->with([
        'ampersand and quotes' => ['page=1&x="it\'s"', 'page=1&x="it\'s"', 'page=1'],
        'hash (the delimiter)' => ['ab#c', 'xab#cx', 'abc'],
        'regex symbols' => ['(a+)?', 'q=(a+)?', 'q=aa'],
        'percent-brace' => ['%{HTTP_HOST}', 'x=%{HTTP_HOST}', 'x=host'],
        // Apache collapses `\\` in config arguments; a single escape left
        // the backslash escaping the next character instead.
        'backslash' => ['c:\\tmp', 'path=c:\\tmp', 'path=c:tmp'],
    ]);

    it('refuses control characters with a validation error, not a failed config test', function (string $field) {
        fakeWafWebServer();

        $this->withHeaders(wafHeaders())->putJson("/api/applications/{$this->application->id}/waf", [
            'enabled' => true, 'mode' => 'enforce', $field => ["x\nreturn 200 pwned;"],
        ])->assertStatus(422)->assertJsonValidationErrors("{$field}.0");
    })->with(['exceptions', 'custom_rules']);
});

/*
 * Bug #82: an exception skips every check. Matched against the query string
 * or the user agent it was a password anyone could type (`?x=mobiquo`), and a
 * one-letter one switched the firewall off for the whole site.
 */
describe('exceptions (bug #82)', function () {
    it('match the path only, on every web server and site kind', function (string $driver, string $profile) {
        $this->application->forceFill(['serving_profile' => $profile, 'app_port' => 3000])->save();
        $config = wafVhost($driver, ['mobiquo']);

        expect($config)->toContain('mobiquo')
            ->not->toMatch('/(\$args|\$http_user_agent|\$request_uri|QUERY_STRING\}|HTTP_USER_AGENT\}) [^\n]*mobiquo/');
    })->with([NginxDriver::class, ApacheDriver::class, OlsDriver::class])->with(['php', 'static', 'node']);

    it('refuses one shorter than four characters', function (string $short) {
        fakeWafWebServer();

        $this->withHeaders(wafHeaders())->putJson("/api/applications/{$this->application->id}/waf", [
            'enabled' => true, 'mode' => 'enforce', 'exceptions' => [$short],
        ])->assertStatus(422)->assertJsonValidationErrors('exceptions.0');
    })->with(['a', '/', 'wp-']);

    it('leaves a short one saved before the limit out of the config', function () {
        $config = wafVhost(NginxDriver::class, ['/', 'mobiquo']);

        expect($config)->toContain('"mobiquo"')->not->toContain('$uri ~* "/"');
    });
});

describe('the firewall log (bug #84)', function () {
    it('records what nginx blocks, with the rule that matched', function () {
        $writes = [];
        fakeWafWebServer(onWrite: function ($write) use (&$writes) {
            $writes[] = $write;
        });

        $this->withHeaders(wafHeaders())
            ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'enforce', 'categories' => ['query_string']])
            ->assertOk();

        $vhost = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/shop.conf'))['input'];
        $format = 'panel_waf_'.$this->application->id;

        // Blocking left no record at all.
        expect($vhost)
            ->toContain('return 403')
            ->toContain("log_format {$format} ")
            ->toContain('waf=$waf_reason action=blocked')
            ->toContain('set $waf_reason "query_string"')
            ->toContain("access_log {$this->application->wafDetectLogPath()} {$format} if=\$waf_matched;")
            // log_format is http-level only: above the server block.
            ->and(strpos($vhost, 'log_format'))->toBeLessThan(strpos($vhost, 'server {'));
    });

    it('records what Apache blocks, and not what an exception let through', function () {
        $this->application->forceFill(['waf_enabled' => true, 'waf_mode' => 'enforce', 'waf_categories' => ['cookie']])->save();

        $vhost = app(ApacheDriver::class)->renderConfig($this->application->load('systemUser'), '/home/siteowner/shop/public_html');

        expect($vhost)->toContain('waf=cookie action=blocked" "expr=-n reqenv(\'waf_cookie\') && -z reqenv(\'waf_exception\')"');
    });

    it('empties the log when the firewall is switched off', function () {
        $this->application->forceFill(['waf_enabled' => true, 'waf_mode' => 'detect'])->save();
        $runs = new ArrayObject;
        fakeWafWebServer();
        Process::fake(function ($process) use ($runs) {
            $runs[] = $process->command;

            return Process::result(exitCode: 0);
        });

        $this->withHeaders(wafHeaders())->putJson(wafUrl(), ['enabled' => false, 'mode' => 'detect'])->assertOk();

        expect(collect($runs)->contains(fn (array $c) => in_array('truncate', $c, true)
            && in_array($this->application->wafDetectLogPath(), $c, true)))->toBeTrue();
    });
});
