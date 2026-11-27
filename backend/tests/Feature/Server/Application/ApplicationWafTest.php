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
 * The 8G Firewall: six switchable categories, detect vs enforce, and per-app exceptions and
 * custom rules. A disabled category must leave the rendered vhost, the shared nginx maps file
 * is written once per apply, and a failed config test rolls back columns and rules together.
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

    // The rules are in 8g-firewall.conf in the site's conf folder, which the vhost includes.
    $vhostWrite = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/conf/nginx/8g-firewall.conf'));
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

    // The rules are in 8g-firewall.conf in the site's conf folder, which the vhost includes.
    $vhostWrite = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/conf/nginx/8g-firewall.conf'));

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

    // The rules are in 8g-firewall.conf in the site's conf folder, which the vhost includes.
    $vhostWrite = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/conf/nginx/8g-firewall.conf'));

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
        // A bare title ("Bad cookies") is not enough to decide whether switching a category off
        // is safe; that is the normal way to fix a false positive.
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

    // Changing only the mode must not silently switch the other categories back on.
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

    // Same rows, not re-created ones: re-creating would reset their timestamps.
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

    // Watching: the whole point of detect mode is that this log can be read.
    expect($keys())->toContain('waf_detect');

    $this->withHeaders(wafHeaders())->putJson(wafUrl(), [
        'enabled' => true, 'mode' => 'enforce',
    ])->assertOk();

    // Still listed after enforcing: the flow is detect → read the log → add exceptions → enforce.
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

    // Where nginx was configured to log, read back from the written vhost (not recomputed here).
    $vhost = collect($written)->pluck('input')->first(
        // `access_log`: the logrotate policy names the file too.
        fn (string $body): bool => str_contains($body, 'access_log') && str_contains($body, 'waf-detect.log'),
    );

    preg_match('#access_log (\S*waf-detect\.log)#', (string) $vhost, $matches);
    $writerPath = $matches[1] ?? null;

    // Where the panel looks when the user opens the log.
    $readerPath = app(ApplicationLogManager::class)
        ->find($this->application->fresh(), 'waf_detect')['path'] ?? null;

    // The two paths must agree: if the panel reads another file, detect mode shows an empty log,
    // which reads as "nothing would be blocked". Offering the key alone does not prove it.
    expect($writerPath)->not->toBeNull()
        ->and($readerPath)->toBe($writerPath);
});

/**
 * A web server that cannot enforce the WAF. None of the three supported ones is such a server
 * now, but the guard stays for a future one, so it is tested against a stand-in.
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

    // Not in the feature list, so not in the sidebar: hidden rather than shown-and-refused.
    expect($this->application->fresh()->features())->not->toContain('app_firewall');

    // CheckPermission 404s the routes off the same list. Answering 200 would store
    // waf_enabled: true while no template references the rules: a firewall inspecting nothing.
    $this->withHeaders(wafHeaders())
        ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'enforce'])
        ->assertNotFound();

    expect($this->application->fresh()->waf_enabled)->toBeFalse();
});

it('keeps the firewall visible when the web server is not yet known', function () {
    fakeWafWebServer();

    // A fresh box has no capability row yet and features() runs on every application route:
    // hiding a working screen on an unknown web server is worse than showing one, so fail open.
    ServerCapability::query()->delete();

    expect($this->application->fresh()->features())->toContain('app_firewall');
});

it('still lets an unsupported web server switch the firewall off', function () {
    fakeWafWebServer();

    webServerWithoutWaf();

    // Asserted against the guard, not the HTTP stack: rendering an OLS vhost needs a real shared
    // config this fake lacks. A site enabled before the guard must stay recoverable, so
    // switching off is never refused.
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

        preg_match('/if \(\$request_uri ~\* "\^\[\^\?\]\*((?:[^"\\\\]|\\\\.)*)"\) \{ set \$waf_exception "1"; \}/', $config, $m);

        expect($m)->not->toBeEmpty()
            ->and(nginxMatches($m[1], 'a=b&'.$value))->toBeTrue()
            ->and(nginxMatches($m[1], 'page=1'))->toBeFalse();
    });

    it('matches the text literally on nginx, backslashes and regex symbols included', function (string $value, string $hit, string $miss) {
        $config = wafVhost(NginxDriver::class, [$value]);
        preg_match('/if \(\$request_uri ~\* "\^\[\^\?\]\*((?:[^"\\\\]|\\\\.)*)"\) \{ set \$waf_exception "1"; \}/', $config, $m);

        expect(nginxMatches($m[1], $hit))->toBeTrue()
            ->and(nginxMatches($m[1], $miss))->toBeFalse();
    })->with([
        'dot' => ['ab.c', 'xab.cx', 'abxc'],
        'backslash' => ['c:\\tmp', 'path=c:\\tmp', 'path=c:tmp'],
        'regex symbols' => ['(a+)?', 'q=(a+)?', 'q=aa'],
    ]);

    it('matches an exception on the path only on Apache, a block rule on the query too', function () {
        $config = wafVhost(ApacheDriver::class, ['action=upload']);

        // An exception that matches the query string is a password anyone could type. Block rules
        // still read the query.
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
        // Apache collapses `\\` in config arguments; a single escape would escape the next char.
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
 * An exception skips every check. Matched against the query string or user agent it would be a
 * password anyone could type (`?x=mobiquo`), and a one-letter one would switch the firewall off.
 */
describe('exceptions', function () {
    it('match the path only, on every web server and site kind', function (string $driver, string $profile) {
        $this->application->forceFill(['serving_profile' => $profile, 'app_port' => 3000])->save();
        $config = wafVhost($driver, ['mobiquo']);

        expect($config)->toContain('mobiquo')
            // `$request_uri` only in its path-only form.
            ->not->toMatch('/(\$args|\$http_user_agent|\$request_uri(?! ~\* "\^\[\^\?\]\*)|QUERY_STRING\}|HTTP_USER_AGENT\}) [^\n]*mobiquo/');
    })->with([NginxDriver::class, ApacheDriver::class, OlsDriver::class])->with(['php', 'static', 'node']);

    it('refuses one shorter than four characters', function (string $short) {
        fakeWafWebServer();

        $this->withHeaders(wafHeaders())->putJson("/api/applications/{$this->application->id}/waf", [
            'enabled' => true, 'mode' => 'enforce', 'exceptions' => [$short],
        ])->assertStatus(422)->assertJsonValidationErrors('exceptions.0');
    })->with(['a', '/', 'wp-']);

    it('leaves a short one saved before the limit out of the config', function () {
        $config = wafVhost(NginxDriver::class, ['/', 'mobiquo']);

        expect($config)->toContain('^[^?]*mobiquo"')->not->toContain('^[^?]*/"');
    });
});

describe('the firewall log', function () {
    it('records what nginx blocks, with the rule that matched', function () {
        $writes = [];
        fakeWafWebServer(onWrite: function ($write) use (&$writes) {
            $writes[] = $write;
        });

        $this->withHeaders(wafHeaders())
            ->putJson(wafUrl(), ['enabled' => true, 'mode' => 'enforce', 'categories' => ['query_string']])
            ->assertOk();

        $vhost = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/shop.conf'))['input'];
        $rules = collect($writes)->first(fn ($w) => str_ends_with($w['path'], '/conf/nginx/8g-firewall.conf'))['input'];
        $format = 'panel_waf_'.$this->application->id;

        // Blocking left no record at all.
        expect($rules)
            ->toContain('return 403')
            ->toContain('set $waf_reason "query_string"')
            ->toContain("access_log {$this->application->wafDetectLogPath()} {$format} if=\$waf_matched;")
            // log_format is http-level only: in the vhost, above the server block.
            ->and($vhost)->toContain("log_format {$format} ", 'waf=$waf_reason action=blocked')
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

describe('exceptions on front-controller routes', function () {
    it('matches the requested path, which survives the internal redirect to index.php', function (string $profile) {
        $this->application->forceFill(['serving_profile' => $profile, 'app_port' => 3000])->save();
        $config = wafVhost(NginxDriver::class, ['/wp-json/']);

        expect($config)->toContain('if ($request_uri ~* "^[^?]*/wp\-json/") { set $waf_exception "1"; }')
            ->not->toContain('if ($uri ~* "/wp\-json/")')
            // A dot segment or doubled slash must not carry the exception to
            // another path.
            ->toContain('if ($request_uri ~* "^[^?]*(/\.\.|%2e%2e|\.%2e|%2e\.|//|%2f)") { set $waf_exception "0"; }');

        // The dot-segment guard comes after the exceptions, so it wins.
        expect(strpos($config, '{ set $waf_exception "0"; }', strpos($config, '/wp\-json/')))->not->toBeFalse();
    })->with(['php', 'static', 'node']);

    it('refuses an exception with .. or ?', function (string $value) {
        fakeWafWebServer();

        $this->withHeaders(wafHeaders())->putJson("/api/applications/{$this->application->id}/waf", [
            'enabled' => true, 'mode' => 'enforce', 'exceptions' => [$value],
        ])->assertStatus(422)->assertJsonValidationErrors('exceptions.0');
    })->with(['../wp-admin', '/page?x=1']);
});
