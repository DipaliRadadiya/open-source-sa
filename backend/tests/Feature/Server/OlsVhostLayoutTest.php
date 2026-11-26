<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Services\Server\Sync\Discoverers\ApplicationDiscoverer;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\OlsSharedConfig;
use App\Services\Server\WebServers\OlsVhostLayout;
use Illuminate\Support\Facades\Process;

/*
 * A v7 server installs this panel and must be able to manage what is already there. Discovery
 * globbed `<configured root>/<site>/vhconf.conf` while the old panel wrote
 * `/etc/<brand>-ols/<site>/main.conf`: the sync found zero OpenLiteSpeed sites and reported a
 * clean run, so nothing looked broken.
 *
 * `<brand>` is the old panel's build-time name (a reseller's own brand on their boxes), so it is
 * found by looking rather than from any list shipped here.
 */

beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create(['web_server' => 'openlitespeed', 'source' => 'detected']);

    config(['server.web_server_drivers.openlitespeed.vhost_root' => '/usr/local/lsws/conf/vhosts']);
});

/**
 * A box where `find` answers for the directories named, and finds nothing anywhere else.
 *
 * Faked per command: one blanket success would make every probe report a vhost.
 *
 * @param  array<int, string>  $etcDirs  what `/etc/*-ols` returns
 * @param  array<int, string>  $withVhosts  directories that actually hold one
 */
function fakeOlsBox(array $etcDirs, array $withVhosts, array $managedSites = []): void
{
    Process::fake(function ($process) use ($etcDirs, $withVhosts, $managedSites) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        // httpd_config.conf: the panel's managed map region, holding the sites it has registered.
        if (($args[0] ?? '') === 'cat') {
            $maps = implode("\n", array_map(fn ($site) => "  map                     {$site} {$site}.example.com", $managedSites));

            return Process::result(output: "listener Default {\n  ### BEGIN panel-managed maps — do not edit between these markers\n{$maps}\n  ### END panel-managed maps\n}\n");
        }

        if (($args[0] ?? '') !== 'find') {
            return Process::result(exitCode: 0);
        }

        // The candidate listing: `find /etc -maxdepth 1 -type d -name *-ols`.
        if (in_array('*-ols', $args, true)) {
            return Process::result(output: implode("\n", $etcDirs)."\n");
        }

        // A probe: `find <dir> -mindepth 2 ... -print -quit`.
        $directory = $args[1] ?? '';

        return in_array($directory, $withVhosts, true)
            ? Process::result(output: rtrim($directory, '/').'/site/main.conf'."\n")
            : Process::result(output: '');
    });
}

it('finds the old panels directory whatever it is branded', function () {
    fakeOlsBox(
        etcDirs: ['/etc/sureshcloud-ols'],
        withVhosts: ['/etc/sureshcloud-ols'],
    );

    expect(app(OlsVhostLayout::class)->detect())->toBe('/etc/sureshcloud-ols')
        ->and(ServerCapability::query()->value('ols_vhost_root'))->toBe('/etc/sureshcloud-ols');
});

it('keeps its own directory when this panel already manages the box', function () {
    // Both present: a server this panel created sites on that also carries the
    // old panel's leftovers. Moving to the inherited directory would strand
    // every site this panel made.
    fakeOlsBox(
        etcDirs: ['/etc/serveravatar-ols'],
        withVhosts: ['/usr/local/lsws/conf/vhosts', '/etc/serveravatar-ols'],
        managedSites: ['shop'],
    );

    expect(app(OlsVhostLayout::class)->detect())->toBe('/usr/local/lsws/conf/vhosts')
        ->and(ServerCapability::query()->value('ols_vhost_root'))->toBeNull();
});

it('takes a v7 server for one, even with this panel\'s own vhosts beside it', function () {
    // This panel installed on a v7 server: its own vhosts (panel, panel-api) and OpenLiteSpeed's
    // Example are in the configured root, but no site is. Those are not sites, and the v7 sites
    // must stay where v7 put them.
    fakeOlsBox(
        etcDirs: ['/etc/serveravatar-ols'],
        withVhosts: ['/usr/local/lsws/conf/vhosts', '/etc/serveravatar-ols'],
    );

    expect(app(OlsVhostLayout::class)->detect())->toBe('/etc/serveravatar-ols')
        ->and(ServerCapability::query()->value('ols_vhost_root'))->toBe('/etc/serveravatar-ols');
});

it('ignores a branded directory that holds no vhost', function () {
    // An uninstall leaves the directory behind, and a directory existing is not an installation
    // (php-fpm ships an /etc/apache2 on boxes with no Apache).
    fakeOlsBox(
        etcDirs: ['/etc/oldbrand-ols'],
        withVhosts: [],
    );

    expect(app(OlsVhostLayout::class)->detect())->toBe('/usr/local/lsws/conf/vhosts')
        ->and(ServerCapability::query()->value('ols_vhost_root'))->toBeNull();
});

it('prefers the recorded root over the configured default', function () {
    ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/acme-ols'])->save();

    // A recorded root beats a default that is a guess.
    expect(app(OlsVhostLayout::class)->root())->toBe('/etc/acme-ols');
});

it('looks for both filenames, ours first', function () {
    // `main.conf` is the old panel's; `vhconf.conf` is this one's. A find that
    // names only one of them is how the sync came back empty.
    expect(OlsVhostLayout::FILENAMES)->toBe(['vhconf.conf', 'main.conf'])
        ->and(app(OlsVhostLayout::class)->nameTests())
        ->toBe(['-name', 'vhconf.conf', '-o', '-name', 'main.conf']);
});

it('writes a vhost where a migrated server already points', function () {
    /*
     * The old panel's `httpd_config.conf` already has a `virtualHost` block naming
     * `/etc/<brand>-ols/<site>/main.conf`. Writing there means taking a server over needs no edit
     * to that file, and editing it is the dangerous part of OpenLiteSpeed support: a mistake
     * breaks every site. Writing this panel's own path would leave the config where nothing
     * points.
     */
    ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/sureshcloud-ols'])->save();

    $application = Application::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);

    expect(app(OlsDriver::class)->configPath($application))
        ->toBe('/etc/sureshcloud-ols/shop/main.conf');
});

it('writes its own layout on a server that never ran the old panel', function () {
    $application = Application::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);

    expect(app(OlsDriver::class)->configPath($application))
        ->toBe('/usr/local/lsws/conf/vhosts/shop/vhconf.conf');
});

it('points the shared config at the same file the driver writes', function () {
    /*
     * The two halves must agree: `configPath()` knew where a migrated server keeps its vhosts and
     * `vhostBlock()` did not, so config was written to one path and OpenLiteSpeed told to read
     * another.
     */
    ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/sureshcloud-ols'])->save();

    $application = Application::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);
    $written = app(OlsDriver::class)->configPath($application);

    $block = (new ReflectionMethod(OlsSharedConfig::class, 'vhostBlock'))
        ->invoke(app(OlsSharedConfig::class), 'shop', '/home/shopuser/shop');

    expect($block)->toContain("configFile              {$written}")
        ->and($written)->toBe('/etc/sureshcloud-ols/shop/main.conf');
});

it('names a migrated site after its directory, not after main.conf', function () {
    /*
     * Every OpenLiteSpeed vhost file has a fixed name, so the basename would call every site the
     * same, and the tracked-slug and exclusion checks are keyed on it. A v7 path ends in
     * `main.conf`; falling through to the filename branch made every site `main`.
     */
    $name = (new ReflectionMethod(ApplicationDiscoverer::class, 'vhostName'))
        ->invoke(app(ApplicationDiscoverer::class), '/etc/sureshcloud-ols/shop/main.conf');

    expect($name)->toBe('shop');
});

it('can still delete a site on a migrated server', function () {
    /*
     * `remove()` guards its `rm -rf` by requiring the directory to sit beneath the vhost root (a
     * blank slug would otherwise make it the root itself). The guard read the *configured* root
     * while the directory came from the detected one, so on a migrated box they never agreed and
     * every delete aborted: failing closed, but a site could not be removed.
     */
    ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/sureshcloud-ols'])->save();

    $application = Application::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);

    $directory = dirname(app(OlsDriver::class)->configPath($application));
    $root = rtrim(app(OlsVhostLayout::class)->root(), '/');

    expect($directory)->toBe('/etc/sureshcloud-ols/shop')
        // What the guard asks: under the root, and not the root itself.
        ->and($directory !== $root && str_starts_with($directory, $root.'/'))->toBeTrue();
});
