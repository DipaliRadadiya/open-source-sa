<?php

use App\Actions\Server\Application\SetForceHttps;
use App\Enums\AiBotPolicy;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Enums\WafMode;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\BotBlockerManager;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/*
 * Point 4 of the v7 names plan (Dipali, 2026-11-11): a site's bot blocker,
 * 8G firewall and — on Apache — force HTTPS are written as v7 writes them,
 * one file each in the site's conf folder, which the vhost loads. On a site
 * from v7 those files are v7's own and keep working: v8 never writes over a
 * file it did not write, and moves v7's aside only when the owner changes
 * that setting in v8.
 */

const RULES_ROOT = '/home/v7demo/shop';

/** v7's own Apache bot blocker, as on a real v7 server. */
const V7_APACHE_BOTS = "<IfModule mod_rewrite.c>\n\tRewriteEngine On\n\tRewriteCond %{HTTP_USER_AGENT} YandexBot [NC]\n\tRewriteRule ^ - [L]\n\tRewriteCond %{HTTP_USER_AGENT} (GPTBot) [NC]\n\tRewriteRule ^ - [F,L]\n</IfModule>\n";

/** v7's own Apache forceHttps.conf. */
const V7_APACHE_FORCE = "# Force SSL\nRewriteEngine On\nRewriteCond %{HTTPS} !=on\nRewriteRule !^/.well-known($|/) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";

function rulesServer(string $webServer): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => ['nginx' => 'lemp', 'apache' => 'lamp', 'openlitespeed' => 'ols'][$webServer],
        'web_server' => $webServer, 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
    ]);
    app()->forgetInstance(WebServerManager::class);
}

function rulesSite(array $forced = []): Application
{
    $user = SystemUser::firstOrCreate(['username' => 'v7demo'], ['home_path' => '/home/v7demo']);
    $site = Application::forceCreate(array_merge([
        'system_user_id' => $user->id, 'name' => 'shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ], $forced));
    $site->domains()->create(['domain' => 'shop.test', 'type' => DomainType::Primary]);

    return $site->fresh(['domains', 'certificate', 'systemUser', 'botRules', 'wafRules']);
}

function rulesCertificate(Application $site, bool $force): void
{
    Certificate::create([
        'application_id' => $site->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
        'domains' => ['shop.test'], 'issued_at' => now(), 'expires_at' => now()->addDays(89), 'force_https' => $force,
        'certificate_path' => '/etc/letsencrypt/live/shop/fullchain.pem', 'private_key_path' => '/etc/letsencrypt/live/shop/privkey.pem',
    ]);
}

function rulesFiles(Application $site): array
{
    $site = $site->fresh(['domains', 'certificate', 'systemUser', 'botRules', 'wafRules']);

    return app(WebServerManager::class)->driver()->siteConfFiles($site, app(ApplicationProvisioner::class)->documentRoot($site));
}

/**
 * A disk the fake commands read and write.
 *
 * @param  array<string, string>  $files
 */
function rulesDisk(array $files = [], ?Closure $testPasses = null): ArrayObject
{
    $state = new ArrayObject(['files' => $files, 'ran' => []]);

    Process::fake(function ($process) use ($state, $testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $files = $state['files'];
        $state['ran'] = [...$state['ran'], implode(' ', $args)];
        $path = (string) end($args);

        $result = match ($args[0] ?? '') {
            'cat' => isset($files[$path]) ? Process::result(output: $files[$path]) : Process::result(exitCode: 1, errorOutput: 'No such file or directory'),
            'tee' => (function () use (&$files, $args, $process) {
                $files[$args[1]] = (string) $process->input;

                return Process::result();
            })(),
            'rm' => (function () use (&$files, $path) {
                unset($files[$path]);

                return Process::result();
            })(),
            'mv' => (function () use (&$files, $args) {
                [$from, $to] = array_slice($args, -2);
                if (isset($files[$from])) {
                    $files[$to] = $files[$from];
                    unset($files[$from]);
                }

                return Process::result();
            })(),
            'stat' => Process::result(output: isset($files[$path]) ? "regular file\n" : "directory\n"),
            'nginx', 'apache2ctl', 'apachectl', '/usr/local/lsws/bin/openlitespeed' => in_array('-t', $args, true) || ($args[0] ?? '') !== 'nginx'
                ? Process::result(exitCode: $testPasses === null || $testPasses($files) ? 0 : 1, errorOutput: 'refused')
                : Process::result(),
            default => Process::result(),
        };

        $state['files'] = $files;

        return $result;
    });

    return $state;
}

describe('which file goes where', function () {
    it('gives nginx v7\'s two files in conf/nginx, and the vhost no rules of its own', function () {
        rulesServer('nginx');
        $site = rulesSite(['ai_bot_policy' => AiBotPolicy::BlockTraining, 'waf_enabled' => true, 'waf_mode' => WafMode::Enforce, 'waf_categories' => ['query_string']]);

        $files = rulesFiles($site);

        expect(array_keys($files))->toBe([RULES_ROOT.'/conf/nginx/8g-firewall.conf', RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf'])
            ->and($files[RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf'])->toStartWith('# Managed by the panel')->toContain('GPTBot', 'return 403;')
            ->and($files[RULES_ROOT.'/conf/nginx/8g-firewall.conf'])->toContain('if ($bad_querystring_ng)', 'return 403;');

        $vhost = app(WebServerManager::class)->driver()->configFiles($site, app(ApplicationProvisioner::class)->documentRoot($site))['/etc/nginx/sites-available/shop.conf'];

        expect($vhost)->toContain('include '.RULES_ROOT.'/conf/nginx/*.conf;')
            ->not->toContain('$http_user_agent ~*')
            ->not->toContain('$bad_querystring_ng');
    });

    it('gives Apache v7\'s three files in conf/apache, blocking by rewrite before the password', function () {
        rulesServer('apache');
        $site = rulesSite(['ai_bot_policy' => AiBotPolicy::BlockTraining, 'waf_enabled' => true, 'waf_mode' => WafMode::Enforce, 'waf_categories' => ['query_string', 'method']]);
        rulesCertificate($site, force: true);

        $files = rulesFiles($site);

        expect(array_keys($files))->toBe([RULES_ROOT.'/conf/apache/8g-firewall.conf', RULES_ROOT.'/conf/apache/ai-bot-blocker.conf', RULES_ROOT.'/conf/apache/forceHttps.conf'])
            ->and($files[RULES_ROOT.'/conf/apache/ai-bot-blocker.conf'])->toContain('SetEnvIfNoCase User-Agent', 'RewriteCond %{ENV:ai_bot_blocked} !^$', 'RewriteRule ^ - [F]')
            ->and($files[RULES_ROOT.'/conf/apache/8g-firewall.conf'])->toContain(
                'RewriteCond %{ENV:waf_exception} !=1',
                'RewriteCond %{ENV:waf_query} !^$ [OR]',
                "RewriteCond %{ENV:waf_method} !^$\nRewriteRule ^ - [F]",
                'waf=method action=blocked',
            )
            ->and($files[RULES_ROOT.'/conf/apache/forceHttps.conf'])->toContain('RewriteCond %{HTTPS} !=on', 'RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]');

        // Port 80 is the site itself, as on v7, and forceHttps.conf sends it on.
        $main = app(WebServerManager::class)->driver()->configFiles($site->fresh(['domains', 'certificate', 'systemUser', 'botRules', 'wafRules']), app(ApplicationProvisioner::class)->documentRoot($site))['/etc/apache2/sites-available/shop.conf'];

        expect($main)->toContain('<VirtualHost *:80>', 'DocumentRoot', 'IncludeOptional '.RULES_ROOT.'/conf/apache/*')
            ->not->toContain('RedirectMatch')
            ->not->toContain('Require not env ai_bot_blocked')
            ->not->toContain('Require env waf_query');
    });

    it('gives OpenLiteSpeed v7\'s two files in conf/openlitespeed/rewrites', function () {
        rulesServer('openlitespeed');
        $site = rulesSite(['ai_bot_policy' => AiBotPolicy::BlockTraining, 'waf_enabled' => true, 'waf_mode' => WafMode::Enforce, 'waf_categories' => ['query_string']]);

        $files = rulesFiles($site);

        expect(array_keys($files))->toBe([RULES_ROOT.'/conf/openlitespeed/rewrites/8g-firewall.conf', RULES_ROOT.'/conf/openlitespeed/rewrites/ai-bot-blocker.conf'])
            ->and($files[RULES_ROOT.'/conf/openlitespeed/rewrites/ai-bot-blocker.conf'])->toContain('RewriteCond %{HTTP_USER_AGENT}', '[F,L]')
            ->and($files[RULES_ROOT.'/conf/openlitespeed/rewrites/8g-firewall.conf'])->toContain('RewriteRule ^ - [E=waf_would:1,F,L]');
    });

    it('says null for a rule that is off, so the panel\'s own copy goes', function () {
        rulesServer('nginx');

        expect(rulesFiles(rulesSite(['ai_bot_policy' => AiBotPolicy::AllowAll])))
            ->toBe([RULES_ROOT.'/conf/nginx/8g-firewall.conf' => null, RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf' => null]);
    });
});

describe('v7\'s own files', function () {
    it('are never written over or removed when a site is written again', function () {
        rulesServer('apache');
        $site = rulesSite(['ai_bot_policy' => AiBotPolicy::BlockTraining]);
        $disk = rulesDisk([RULES_ROOT.'/conf/apache/ai-bot-blocker.conf' => V7_APACHE_BOTS, RULES_ROOT.'/conf/apache/forceHttps.conf' => V7_APACHE_FORCE]);

        app(WebServerManager::class)->driver()->apply($site, app(ApplicationProvisioner::class)->documentRoot($site));

        expect($disk['files'][RULES_ROOT.'/conf/apache/ai-bot-blocker.conf'])->toBe(V7_APACHE_BOTS)
            // Force HTTPS is off here, and this is v7's: it stays, as on v7.
            ->and($disk['files'][RULES_ROOT.'/conf/apache/forceHttps.conf'])->toBe(V7_APACHE_FORCE);
    });

    it('are left by sites:resync', function () {
        rulesServer('nginx');
        rulesSite(['ai_bot_policy' => AiBotPolicy::BlockTraining]);
        $v7 = "if (\$shop_block_ai) {\n\treturn 403;\n}\n";
        $disk = rulesDisk([RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf' => $v7]);

        app(SiteConfigResyncer::class)->run();

        expect($disk['files'][RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf'])->toBe($v7)
            ->and(collect($disk['ran'])->contains('tee '.RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf'))->toBeFalse();
    });

    it('are moved aside when the owner changes the setting, and v8\'s takes the name (Apache path fixed)', function () {
        rulesServer('apache');
        $site = rulesSite(['ai_bot_policy' => AiBotPolicy::LegacyV7]);
        $disk = rulesDisk([RULES_ROOT.'/conf/apache/ai-bot-blocker.conf' => V7_APACHE_BOTS]);

        app(BotBlockerManager::class)->apply($site, AiBotPolicy::BlockAll);

        $file = $disk['files'][RULES_ROOT.'/conf/apache/ai-bot-blocker.conf'];
        $backup = collect($disk['files'])->filter(fn ($text, string $path) => str_contains($path, '/v7-leftovers/'));

        expect($file)->toStartWith('# Managed by the panel')
            ->and($backup->values()->all())->toBe([V7_APACHE_BOTS]);
    });

    it('go back, with everything else, when the change is refused', function () {
        rulesServer('apache');
        $site = rulesSite(['ai_bot_policy' => AiBotPolicy::LegacyV7]);
        // Refused while the panel's own bot file is on the disk.
        $disk = rulesDisk([RULES_ROOT.'/conf/apache/ai-bot-blocker.conf' => V7_APACHE_BOTS], fn (array $files) => ! str_starts_with($files[RULES_ROOT.'/conf/apache/ai-bot-blocker.conf'] ?? '', '# Managed'));

        expect(fn () => app(BotBlockerManager::class)->apply($site, AiBotPolicy::BlockAll))->toThrow(Exception::class);

        expect($disk['files'][RULES_ROOT.'/conf/apache/ai-bot-blocker.conf'])->toBe(V7_APACHE_BOTS)
            ->and($site->fresh()->ai_bot_policy)->toBe(AiBotPolicy::LegacyV7);
    });

    it('include v7\'s forceHttps.conf, moved aside when force HTTPS is changed', function () {
        rulesServer('apache');
        $site = rulesSite();
        rulesCertificate($site, force: true);
        $disk = rulesDisk([RULES_ROOT.'/conf/apache/forceHttps.conf' => V7_APACHE_FORCE]);

        app(SetForceHttps::class)->execute($site->fresh('certificate')->certificate, false);

        expect($disk['files'])->not->toHaveKey(RULES_ROOT.'/conf/apache/forceHttps.conf')
            ->and(collect($disk['files'])->filter(fn ($text, string $path) => str_contains($path, '/v7-leftovers/'))->values()->all())->toBe([V7_APACHE_FORCE]);
    });
});

it('removes the panel\'s own rule file when the rule is turned off', function () {
    rulesServer('nginx');
    $site = rulesSite(['ai_bot_policy' => AiBotPolicy::BlockTraining]);
    $disk = rulesDisk();
    app(WebServerManager::class)->driver()->apply($site, app(ApplicationProvisioner::class)->documentRoot($site));
    expect($disk['files'])->toHaveKey(RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf');

    app(BotBlockerManager::class)->apply($site->fresh(['domains', 'certificate', 'systemUser', 'botRules', 'wafRules']), AiBotPolicy::AllowAll);

    expect($disk['files'])->not->toHaveKey(RULES_ROOT.'/conf/nginx/ai-bot-blocker.conf');
});

it('puts every file back when a change is refused, not only the vhost', function () {
    rulesServer('apache');
    $site = rulesSite();
    rulesCertificate($site, force: false);
    // nginx/Apache refuse while forceHttps.conf is there.
    $disk = rulesDisk([], fn (array $files) => ! isset($files[RULES_ROOT.'/conf/apache/forceHttps.conf']));

    expect(fn () => app(SetForceHttps::class)->execute($site->fresh('certificate')->certificate, true))->toThrow(Exception::class);

    expect($disk['files'])->not->toHaveKey(RULES_ROOT.'/conf/apache/forceHttps.conf')
        ->and($site->fresh('certificate')->certificate->force_https)->toBeFalse();
});
