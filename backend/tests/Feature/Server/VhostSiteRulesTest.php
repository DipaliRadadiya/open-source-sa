<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationArtifacts;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Every PHP site's vhost includes a root-owned per-site rules directory that
 * the panel creates and removes but never renders into.
 *
 * Why: the vhost is re-rendered from its template on every domain, certificate
 * and HTTPS change, so a rule an addon writes into the vhost itself is gone at
 * the next one. v7 solved this the same way — a directory its vhosts included —
 * but put it in the site's home, owned by the site user (who could then write
 * web server configuration), and on nginx included it *after* the PHP location,
 * so its "block PHP in uploads" rule never matched: regex locations are
 * first-match, and `/wp-content/uploads/x.php` went to PHP. Measured on
 * 2026-10-02 with a throwaway nginx: 502 (reached PHP) in v7's order, 403 with
 * the include first.
 */
beforeEach(function () {
    config(['server.site_rules_root' => '/etc/panel-site-rules']);

    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $user->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ])->load('systemUser');

    $this->root = '/home/siteowner/blog/public_html';
});

it('names a directory outside the site home, per slug', function () {
    expect($this->application->siteRulesPath())->toBe('/etc/panel-site-rules/blog');
});

it('includes the directory from every web server, which never renders into it', function () {
    $nginx = app(NginxDriver::class)->renderConfig($this->application, $this->root);
    $apache = app(ApacheDriver::class)->renderConfig($this->application, $this->root);
    $ols = app(OlsDriver::class)->renderConfig($this->application, $this->root);

    expect($nginx)->toContain('include /etc/panel-site-rules/blog/*.conf;')
        // In both the :80 and the :443 VirtualHost, which share one body.
        ->and(substr_count($apache, 'IncludeOptional /etc/panel-site-rules/blog/*.conf'))->toBeGreaterThanOrEqual(1)
        ->and($ols)->toContain('include /etc/panel-site-rules/blog/*.conf');
});

it('includes it on nginx before the PHP location, or an uploads block never matches', function () {
    $nginx = app(NginxDriver::class)->renderConfig($this->application, $this->root);

    $include = strpos($nginx, 'include /etc/panel-site-rules/blog/*.conf;');
    $php = strpos($nginx, 'location ~ [^/]\.php(/|$)');

    expect($include)->not->toBeFalse()
        ->and($php)->not->toBeFalse()
        ->and($include)->toBeLessThan($php);
});

it('includes it on OpenLiteSpeed inside the rewrite block, the only place rewrite rules are read', function () {
    $ols = app(OlsDriver::class)->renderConfig($this->application, $this->root);

    preg_match('/^rewrite \{(.*?)^\}/ms', $ols, $block);

    expect($block[1] ?? '')->toContain('include /etc/panel-site-rules/blog/*.conf');
});

it('creates the directory as root, 0755, when the site is set up or resynced', function () {
    Process::fake();

    app(NginxDriver::class)->ensureDirectories($this->application);

    Process::assertRan(fn (PendingProcess $p) => str_ends_with(implode(' ', (array) $p->command), 'mkdir -p /etc/panel-site-rules/blog'));
    Process::assertRan(fn (PendingProcess $p) => str_ends_with(implode(' ', (array) $p->command), 'chmod 0755 /etc/panel-site-rules/blog'));
});

it('removes the directory when the site is deleted', function () {
    Process::fake();

    app(ApplicationArtifacts::class)->remove($this->application);

    Process::assertRan(fn (PendingProcess $p) => str_ends_with(implode(' ', (array) $p->command), 'find /etc/panel-site-rules/blog -delete'));
});

// remove() also runs for a resync's legacy-config cleanup — on a copy of the
// site with its slug blanked, which would make the path the root of every
// site's rules — and for a resync rollback, which must not lose the site's
// blocks because its vhost failed a test.
it('never touches the directory when only the vhost is removed', function (string $driver) {
    Process::fake();

    app($driver)->remove($this->application);
    app($driver)->remove((clone $this->application)->forceFill(['slug' => null]));

    Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '/etc/panel-site-rules'));
})->with([NginxDriver::class, ApacheDriver::class]);

it('falls back to the domain on a row from before slugs, like the vhost file', function () {
    $this->application->slug = null;

    expect($this->application->siteRulesPath())->toBe('/etc/panel-site-rules/blog.example.com');
});

it('refuses to delete the root itself when the site has no name at all', function () {
    Process::fake();
    $this->application->slug = '';
    $this->application->domain = '';

    app(ApplicationArtifacts::class)->remove($this->application);

    Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '/etc/panel-site-rules'));
});
