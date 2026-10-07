<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationFail2banManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;

/*
| A site's fail2ban jail bans an address for that site only, in its own
| web-server config. It used to ban at the firewall on 80/443: three wrong
| WordPress logins took the address off every site and off the panel
| itself (frontend QA FB-K, seen live).
*/

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/site-ban-'.uniqid();
    mkdir($this->root);
});

afterEach(function () {
    Process::run(['rm', '-rf', $this->root]);
});

/** Render the ban script for a web server, with a stand-in test and reload. */
function banScript(string $webServer, string $root, bool $testPasses = true, ?string $test = null): string
{
    $path = $root.'/panel-site-ban';
    file_put_contents($path, View::make('server.fail2ban.site-ban-script', [
        'webServer' => $webServer,
        'self' => $path,
        'rulesRootQuoted' => escapeshellarg($root.'/rules'),
        'test' => $test ?? ($testPasses ? 'true' : 'false'),
        'reload' => 'echo reloaded >> '.escapeshellarg($root.'/reloads'),
        'olsTmpQuoted' => escapeshellarg($root.'/lshttpd'),
    ])->render());
    chmod($path, 0755);

    return $path;
}

function runBan(string $script, string ...$args): int
{
    return Process::run(array_merge([$script], $args))->exitCode();
}

it('bans and unbans one address for one site, in nginx syntax', function () {
    $script = banScript('nginx', $this->root);

    expect(runBan($script, 'ban', 'shop', '203.0.113.9'))->toBe(0)
        ->and(runBan($script, 'ban', 'shop', '2001:db8::7'))->toBe(0)
        // Banned twice: one line, no second reload.
        ->and(runBan($script, 'ban', 'shop', '203.0.113.9'))->toBe(0);

    $conf = file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf');
    expect($conf)->toContain("deny 203.0.113.9;\n")->toContain("deny 2001:db8::7;\n")
        ->and(substr_count($conf, 'deny 203.0.113.9;'))->toBe(1)
        ->and(substr_count(file_get_contents($this->root.'/reloads'), 'reloaded'))->toBe(2);

    runBan($script, 'unban', 'shop', '203.0.113.9');

    expect(file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf'))
        ->not->toContain('203.0.113.9')->toContain('deny 2001:db8::7;');

    // Another site is untouched.
    expect(file_exists($this->root.'/rules/blog/panel-fail2ban.conf'))->toBeFalse();
});

it('writes Apache a Require-all-denied for the banned addresses only', function () {
    $script = banScript('apache', $this->root);
    runBan($script, 'ban', 'shop', '203.0.113.9');

    expect(file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf'))
        ->toContain("SetEnvIfExpr \"-R '203.0.113.9'\" panel_banned")
        ->toContain("<If \"-n reqenv('panel_banned')\">")
        ->toContain('Require all denied');
});

it('writes OpenLiteSpeed one rewrite rule for all banned addresses', function () {
    $script = banScript('openlitespeed', $this->root);
    runBan($script, 'ban', 'shop', '203.0.113.9');
    runBan($script, 'ban', 'shop', '198.51.100.4');

    expect(file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf'))
        ->toContain("RewriteCond %{REMOTE_ADDR} =203.0.113.9 [OR]\nRewriteCond %{REMOTE_ADDR} =198.51.100.4\nRewriteRule ^ - [F,L]");
});

it('empties the file on flush, and leaves nothing to deny', function () {
    $script = banScript('nginx', $this->root);
    runBan($script, 'ban', 'shop', '203.0.113.9');
    runBan($script, 'flush', 'shop');

    expect(trim(file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf')))->toBe('');
});

it('puts the previous file back when the web server rejects the new one', function () {
    banScript('nginx', $this->root);
    runBan($this->root.'/panel-site-ban', 'ban', 'shop', '203.0.113.9');

    $failing = banScript('nginx', $this->root, testPasses: false);

    expect(runBan($failing, 'ban', 'shop', '198.51.100.4'))->toBe(1)
        ->and(file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf'))
        ->toContain('deny 203.0.113.9;')->not->toContain('198.51.100.4');
});

it('refuses a site name or address that could step outside its directory', function (string $slug, string $ip) {
    $script = banScript('nginx', $this->root);

    expect(runBan($script, 'ban', $slug, $ip))->toBe(2);
})->with([
    'parent' => ['..', '203.0.113.9'],
    'slash' => ['a/b', '203.0.113.9'],
    'hidden' => ['.x', '203.0.113.9'],
    'address with a space' => ['shop', '1.2.3.4 ; rm'],
    'address with a newline' => ['shop', "1.2.3.4\nallow all"],
]);

it('names the panel ban action in every site jail, for that site', function () {
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);
    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    $app = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $manager = app(ApplicationFail2banManager::class);
    $jail = $manager->renderConfigs($app, $manager->defaultJailContent(), $manager->defaultFilterContent(), withBanAction: true)['jail'];

    expect($jail)->toContain('action   = panel-site-ban[slug="shop"]')
        ->and(substr_count($jail, 'action'))->toBe(1);

    // The form never sees it: `action` is not a key a user may save, so a
    // pre-filled form that carried it could never be saved.
    expect($manager->renderConfigs($app, $manager->defaultJailContent(), $manager->defaultFilterContent())['jail'])
        ->not->toContain('action');
});

it('includes the site\'s ban file in every vhost kind on every web server', function (string $driver, string $profile, string $line) {
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true, 'node' => true], 'source' => 'installer', 'verified_at' => now()]);
    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    $app = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => $profile === 'php' ? 'php' : ($profile === 'node' ? 'git' : 'static'), 'serving_profile' => $profile,
        'status' => 'active', 'web_root' => '/', 'php_version' => $profile === 'php' ? '8.4' : null,
        'app_port' => $profile === 'node' ? 3000 : null, 'start_command' => $profile === 'node' ? 'node server.js' : null,
    ]);

    $config = app((string) config("server.web_server_drivers.{$driver}.driver"))
        ->renderConfig($app->fresh(['domains', 'certificate', 'systemUser']), app(ApplicationProvisioner::class)->documentRoot($app));

    expect($config)->toContain(str_replace('{rules}', $app->siteRulesPath(), $line));
})->with([
    'nginx php' => ['nginx', 'php', 'include {rules}/*.conf;'],
    'nginx node' => ['nginx', 'node', 'include {rules}/panel-fail2ban[.]conf;'],
    'nginx static' => ['nginx', 'static', 'include {rules}/panel-fail2ban[.]conf;'],
    'apache php' => ['apache', 'php', 'IncludeOptional {rules}/*.conf'],
    'apache node' => ['apache', 'node', 'IncludeOptional {rules}/panel-fail2ban.conf'],
    'apache static' => ['apache', 'static', 'IncludeOptional {rules}/panel-fail2ban.conf'],
    'ols php' => ['openlitespeed', 'php', 'include {rules}/*.conf'],
    'ols node' => ['openlitespeed', 'node', 'include {rules}/panel-fail2ban*.conf'],
    'ols static' => ['openlitespeed', 'static', 'include {rules}/panel-fail2ban*.conf'],
]);

it('reads OpenLiteSpeed\'s config test the way the panel does: warnings pass, errors do not', function (string $test, bool $applied) {
    // `openlitespeed -t` exits 1 for warnings only — an unrelated site's
    // missing folder is enough — and 2 for errors. Taken as a failure, every
    // ban was thrown away (measured on the OLS test box).
    $script = banScript('openlitespeed', $this->root, test: $test);

    runBan($script, 'ban', 'shop', '203.0.113.9');

    expect(str_contains((string) @file_get_contents($this->root.'/rules/shop/panel-fail2ban.conf'), '203.0.113.9'))->toBe($applied);
})->with([
    'clean' => ['sh -c "exit 0"', true],
    'warnings only' => ['sh -c "echo warn; exit 1"', true],
    'warning that came with stderr' => ['sh -c "echo oops >&2; exit 1"', false],
    'errors' => ['sh -c "exit 2"', false],
]);
