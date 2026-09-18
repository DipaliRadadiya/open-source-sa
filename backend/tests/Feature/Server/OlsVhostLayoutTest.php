<?php

use App\Models\ServerCapability;
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
