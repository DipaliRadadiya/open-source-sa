<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Application;
use App\Models\ApplicationWafRule;
use App\Models\Certificate;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationLogManager;
use App\Services\Server\Waf\OlsWafRuleset;
use App\Services\Server\WebServers\OlsDriver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * 8G on OpenLiteSpeed (2026-09-30): the same ruleset as Apache, rendered as
 * rewrite conditions in the site's vhconf. Every trap below was hit on the
 * OLS test server before it was written down.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create(['stack' => 'ols', 'web_server' => 'openlitespeed', 'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now()]);

    $su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->site = Application::forceCreate([
        'system_user_id' => $su->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);
});

/**
 * @param  array<int, string>  $exceptions
 * @param  array<int, string>  $custom
 * @param  array<int, string>|null  $categories
 */
function olsVhost(string $mode = 'enforce', array $exceptions = [], array $custom = [], ?array $categories = null, string $profile = 'php'): string
{
    $site = test()->site;
    $site->forceFill(['waf_enabled' => true, 'waf_mode' => $mode, 'waf_categories' => $categories, 'serving_profile' => $profile])->save();
    $site->setRelation('wafRules', collect([
        ...array_map(fn ($v) => new ApplicationWafRule(['type' => 'exception', 'value' => $v]), $exceptions),
        ...array_map(fn ($v) => new ApplicationWafRule(['type' => 'block', 'value' => $v]), $custom),
    ]));

    return app(OlsDriver::class)->renderConfig($site->load('systemUser'), '/home/siteowner/shop/public_html');
}

describe('the ruleset', function () {
    it('reads every 8G rule into its category', function () {
        $counts = array_map('count', app(OlsWafRuleset::class)->all());

        expect($counts)->toBe(['query_string' => 41, 'request_uri' => 57, 'user_agent' => 14, 'referrer' => 4, 'cookie' => 1, 'method' => 1]);
    });

    it('groups every pattern, so none can start with a condition operator', function () {
        // `<|>|...` (cookie) became "lexicographically less than" and blocked
        // every request; `=?...` and `-------` were an equality and a file test.
        foreach (app(OlsWafRuleset::class)->all() as $rules) {
            foreach ($rules as [, $pattern]) {
                expect($pattern)->toStartWith('(?:')->toEndWith(')');
            }
        }
    });

    it('gives OLS the regex Apache compiles, not the text in the file', function () {
        // Apache halves `\\`: `=?\\\(?:...` is `=?\\(?:...` to it. Raw, OLS
        // saw an unbalanced group and dropped the site's whole rewrite block.
        $patterns = collect(app(OlsWafRuleset::class)->all()['request_uri'])->pluck(1);

        expect($patterns)->toContain("(?:=?\\\\(?:\\'|%27)/?\\.)");

        foreach (app(OlsWafRuleset::class)->all() as $rules) {
            foreach ($rules as [, $pattern]) {
                expect(@preg_match('#'.str_replace('#', '\#', $pattern).'#i', '') !== false)->toBeTrue($pattern);
            }
        }
    });

    it('unescapes as httpd does', function () {
        expect(OlsWafRuleset::apacheUnescape('a\\\\b', null))->toBe('a\\b')
            ->and(OlsWafRuleset::apacheUnescape('a\\"b', '"'))->toBe('a"b')
            ->and(OlsWafRuleset::apacheUnescape('a\\"b', null))->toBe('a\\"b')
            ->and(OlsWafRuleset::apacheUnescape('a\\.b', '"'))->toBe('a\\.b');
    });

    it('refuses a line it does not understand rather than skipping it', function () {
        app(OlsWafRuleset::class)->parse("SetEnvIf Remote_Addr 1.2.3.4 waf_query\n");
    })->throws(RuntimeException::class);

    it('refuses a rule that is not a valid regex once unescaped', function () {
        app(OlsWafRuleset::class)->parse("SetEnvIfNoCase Request_URI (unbalanced waf_uri\n");
    })->throws(RuntimeException::class);
});

describe('the rendered vhost', function () {
    it('offers the firewall on OpenLiteSpeed now', function () {
        expect(app(OlsDriver::class)->supportsWaf())->toBeTrue()
            ->and($this->site->fresh()->features())->toContain('app_firewall');
    });

    it('blocks in enforce mode, after exceptions and rules have marked the request', function () {
        $config = olsVhost('enforce', ['mobiquo'], ['bad-path'], ['query_string', 'cookie']);

        expect($config)
            // Bug #82: the path only — not the query, not the user agent.
            ->toContain("RewriteCond %{REQUEST_URI} mobiquo [NC]\n  RewriteRule ^ - [E=waf_exception:1]")
            ->not->toContain('RewriteCond %{QUERY_STRING} mobiquo')
            ->toContain('RewriteCond %{HTTP_COOKIE} (?:<|>|\\\'|%0A|%0D|%27|%00) [NC]')
            ->toContain('RewriteCond %{REQUEST_URI} bad\-path [NC,OR]')
            ->toContain("  RewriteCond %{ENV:waf_exception} !=1\n  RewriteCond %{ENV:waf_block} !^$\n  RewriteRule ^ - [E=waf_would:1,F,L]")
            // Each rule names itself, for the log line (bug #84).
            ->toContain('RewriteRule ^ - [E=waf_block:cookie]')
            ->toContain('RewriteRule ^ - [E=waf_block:custom_rule]')
            // Only the categories that are on.
            ->not->toContain('%{HTTP_USER_AGENT} (?:')
            // Blocking is logged too (bug #84): it used to leave no record.
            ->toContain('waf=%{waf_would}e reason=%{waf_block}e');
    });

    it('only marks the request in detect mode, and writes the mark into the access log', function () {
        $config = olsVhost('detect');

        expect($config)
            ->toContain("  RewriteCond %{ENV:waf_block} !^$\n  RewriteRule ^ - [E=waf_would:1]")
            // Unquoted: OLS writes a quoted logFormat literally, quotes and all.
            ->toContain('logFormat               %h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" waf=%{waf_would}e reason=%{waf_block}e');

        preg_match('/^rewrite \{.*?^\}/sm', $config, $m);
        expect($m[0])->not->toContain('E=waf_would:1,F,L]');
    });

    it('writes exceptions as literals an unquoted condition cannot misread', function () {
        $config = olsVhost('enforce', ['a b "c" \'d\' !x'], [], ['query_string']);

        expect($config)->toContain('RewriteCond %{REQUEST_URI} a\x20b\x20\x22c\x22\x20\x27d\x27\x20\!x [NC]');
    });

    it('adds the rewrite block to static and Node sites when only the firewall needs one', function (string $profile) {
        // With a certificate, nothing else asks for a rewrite block — no
        // certificate is itself a reason, which would hide a missing `$waf`.
        Certificate::create([
            'application_id' => $this->site->id, 'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active, 'domains' => ['shop.test'],
            'certificate_path' => '/etc/letsencrypt/live/shop.test/fullchain.pem',
            'private_key_path' => '/etc/letsencrypt/live/shop.test/privkey.pem',
        ]);
        $this->site->unsetRelation('certificate');

        $config = olsVhost('enforce', [], [], ['method'], $profile);

        expect($config)->toContain('rewrite {')->toContain('%{REQUEST_METHOD} (?:^(?:connect|debug|move|trace|track)) [NC]');
        // Wrapped: a bare 'static' string is checked as a callable by Pest and
        // raises a deprecation.
    })->with([['static'], ['node']]);

    it('renders nothing of the firewall when it is off', function () {
        $this->site->forceFill(['waf_enabled' => false])->save();
        $config = app(OlsDriver::class)->renderConfig($this->site->fresh()->load('systemUser'), '/home/siteowner/shop/public_html');

        expect($config)->not->toContain('waf_block')->not->toContain('logFormat');
    });
});

describe('detections in the log screen', function () {
    it('reads them from the access log, marked lines only', function () {
        $this->site->forceFill(['waf_enabled' => true, 'waf_mode' => 'detect'])->save();
        Process::fake(fn ($p) => in_array('tail', $p->command, true)
            ? Process::result(output: "1.2.3.4 - - [x] \"GET /?a HTTP/1.1\" 200 1 \"-\" \"UA\" waf=-\n1.2.3.4 - - [x] \"GET /?etc/shadow HTTP/1.1\" 200 1 \"-\" \"UA\" waf=1\n")
            : Process::result());

        $logs = app(ApplicationLogManager::class);
        $source = $logs->find($this->site->fresh(), 'waf_detect');
        $read = $logs->read($this->site->fresh(), 'waf_detect', 50);

        expect($source['path'])->toEndWith('/access.log')
            ->and($read['lines'])->toHaveCount(1)
            ->and($read['lines'][0])->toContain('etc/shadow');
    });

    it('refuses to clear them, which would empty the access log', function () {
        $this->site->forceFill(['waf_enabled' => true, 'waf_mode' => 'detect'])->save();

        $this->actingAs($this->admin)
            ->deleteJson("/api/applications/{$this->site->id}/logs/waf_detect")
            ->assertStatus(422)
            ->assertJsonPath('message', __('app_log.errors.clear_shared'));
    });
});
