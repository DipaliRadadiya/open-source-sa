<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Services\Server\Sync\Discoverers\ApplicationDiscoverer;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\OlsSharedConfig;
use App\Services\Server\WebServers\OlsVhostLayout;
use Illuminate\Support\Facades\Process;

/*
 * A v7 server installs this panel and must be able to manage what is already
 * there. It could not: discovery globbed `<configured root>/<site>/vhconf.conf`
 * while the old panel wrote `/etc/<brand>-ols/<site>/main.conf`. Wrong root and
 * wrong filename, so the sync found zero OpenLiteSpeed sites and reported a
 * clean run — the worst possible shape for this failure, because nothing looks
 * broken.
 *
 * `<brand>` is the old panel's build-time name: `strings.ToLower(ServiceName)`
 * in its agent, its whitelabel's `folder_name` in its backend. On a reseller's
 * box it is that reseller's brand, so it is found by looking rather than by any
 * list shipped here.
 */

beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create(['web_server' => 'openlitespeed', 'source' => 'detected']);

    config(['server.web_server_drivers.openlitespeed.vhost_root' => '/usr/local/lsws/conf/vhosts']);
});

/**
 * A box where `find` answers for the directories named, and finds nothing
 * anywhere else.
 *
 * Faked per command and statefully: one blanket success would make every probe
 * report a vhost and the detection would "pass" against a server the fake had
 * invented.
 *
 * @param  array<int, string>  $etcDirs  what `/etc/*-ols` returns
 * @param  array<int, string>  $withVhosts  directories that actually hold one
 */
function fakeOlsBox(array $etcDirs, array $withVhosts): void
{
    Process::fake(function ($process) use ($etcDirs, $withVhosts) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

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
    );

    expect(app(OlsVhostLayout::class)->detect())->toBe('/usr/local/lsws/conf/vhosts')
        ->and(ServerCapability::query()->value('ols_vhost_root'))->toBeNull();
});

it('ignores a branded directory that holds no vhost', function () {
    // An uninstall leaves the directory behind. A directory existing is not a
    // thing being installed — php-fpm ships an /etc/apache2 on boxes with no
    // Apache, and that has already been mistaken here for the opposite.
    fakeOlsBox(
        etcDirs: ['/etc/oldbrand-ols'],
        withVhosts: [],
    );

    expect(app(OlsVhostLayout::class)->detect())->toBe('/usr/local/lsws/conf/vhosts')
        ->and(ServerCapability::query()->value('ols_vhost_root'))->toBeNull();
});

it('prefers the recorded root over the configured default', function () {
    ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/acme-ols'])->save();

    // Measured on this box beats a default that is a guess about a server
    // nobody has looked at.
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
     * The half that makes adoption cheap. The old panel's `httpd_config.conf`
     * already has a `virtualHost` block naming
     * `/etc/<brand>-ols/<site>/main.conf`. Writing there means taking a server
     * over needs no edit to that file at all — and editing it is the dangerous
     * part of OpenLiteSpeed support, because a mistake is not one broken site,
     * it is all of them.
     *
     * Writing this panel's own path instead would leave the config somewhere
     * nothing points at: a site serving nothing while every file involved
     * looks correct.
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
     * The two halves have to agree. `configPath()` learned where a migrated
     * server keeps its vhosts and `vhostBlock()` did not, so the panel wrote
     * config to one path and told OpenLiteSpeed to read another. Every file
     * involved looks correct on its own and the site serves nothing.
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
     * Every OpenLiteSpeed vhost file has a fixed name, so the basename would
     * call every site on the box the same thing — and the tracked-slug and
     * exclusion checks are keyed on it. That was handled for `vhconf.conf` and
     * reintroduced one layout over: a v7 path ends in `main.conf`, fell through
     * to the filename branch, and every site became `main`.
     */
    $name = (new ReflectionMethod(ApplicationDiscoverer::class, 'vhostName'))
        ->invoke(app(ApplicationDiscoverer::class), '/etc/sureshcloud-ols/shop/main.conf');

    expect($name)->toBe('shop');
});

it('can still delete a site on a migrated server', function () {
    /*
     * `remove()` guards its `rm -rf` by requiring the directory to sit beneath
     * the vhost root — the most destructive command in the panel, and a blank
     * slug would otherwise make it the root itself.
     *
     * The guard read the *configured* root while the directory came from the
     * detected one, so on a migrated box they could never agree and every
     * delete aborted. Failing closed is the right direction to be wrong in; the
     * effect was still that a site on such a server could not be removed.
     */
    ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/sureshcloud-ols'])->save();

    $application = Application::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);

    $directory = dirname(app(OlsDriver::class)->configPath($application));
    $root = rtrim(app(OlsVhostLayout::class)->root(), '/');

    expect($directory)->toBe('/etc/sureshcloud-ols/shop')
        // What the guard asks: under the root, and not the root itself.
        ->and($directory !== $root && str_starts_with($directory, $root.'/'))->toBeTrue();
});

describe('the three-file structure', function () {
    it('splits a site into a declaration, a body and its php settings', function () {
        /*
         * The old panel's shape, and the reason it is worth copying rather than
         * merely tolerating: `<name>.conf` declares the site — listeners,
         * vhDomain, rewrite, vhssl — and includes `<name>/main.conf`, which
         * describes it. httpd_config.conf pulls the whole directory in with one
         * `include <root>/*.conf`, so it needs no per-site block and no listener
         * map at all. The shared file stops being something every site edits.
         */
        ServerCapability::query()->first()->forceFill(['ols_vhost_root' => '/etc/sureshcloud-ols'])->save();

        $layout = app(OlsVhostLayout::class);

        expect($layout->declarationPath('shop'))->toBe('/etc/sureshcloud-ols/shop.conf')
            ->and($layout->bodyPath('shop'))->toBe('/etc/sureshcloud-ols/shop/main.conf')
            ->and($layout->phpPath('shop'))->toBe('/etc/sureshcloud-ols/shop/php.conf');
    });

    it('keeps the body under whatever this server calls a vhost file', function () {
        $layout = app(OlsVhostLayout::class);

        // No legacy root recorded, so this panel's own name.
        expect($layout->bodyPath('shop'))->toBe('/usr/local/lsws/conf/vhosts/shop/vhconf.conf')
            ->and($layout->declarationPath('shop'))->toBe('/usr/local/lsws/conf/vhosts/shop.conf');
    });

    it('puts a sites snippets inside the site, where a migrated box already has them', function () {
        // `rewrites/` is not decoration: the old panel generates WordPress
        // rewrite rules and the AI bot blocker into it, and a rewrite of the
        // vhost that drops the include silently disables both.
        expect(app(OlsVhostLayout::class)->snippetDirs('/home/shopuser/shop'))
            ->toBe([
                'conf' => '/home/shopuser/shop/conf/openlitespeed',
                'rewrites' => '/home/shopuser/shop/conf/openlitespeed/rewrites',
            ]);
    });
});
