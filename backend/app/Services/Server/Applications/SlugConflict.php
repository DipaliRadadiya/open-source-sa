<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\UpdateApplication;
use App\Models\Application;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Would a site with this slug write over a file that is not the panel's?
 *
 * The slug is a filename in three places the panel does not own outright: the
 * PHP-FPM pool directory, the web server's sites directory, and (on
 * OpenLiteSpeed) the vhost directory. Nothing checked, and uniqueness was
 * asked of the `applications` table only — which knows what the panel has
 * made and nothing at all about what is already on the disk. So:
 *
 *  - a site named **www** takes `pool.d/www.conf`, the pool the distro ships
 *    and the one every *non-isolated* PHP site reaches through
 *    `/run/php/php{version}-fpm.sock`. Overwriting it moves that pool onto a
 *    different socket and a different user; deleting the site removes it.
 *  - a site named **panel** takes `sites-available/panel.conf` — the panel's
 *    own vhost, written by install.sh. The reply to that request is the last
 *    thing the API ever says.
 *
 * Both were reachable from create *and* from rename, since a rename
 * regenerates the slug ({@see UpdateApplication}).
 *
 * **Asked of the disk, not of a list of reserved words.** A list would need
 * `www`, `panel`, `panel-le-ssl`, `000-default`, `default-ssl`, `Example`,
 * and whatever the next web server ships — and `config/server.php` already
 * makes the argument against name-matching the panel's own files: install.sh
 * writes `{PANEL_SLUG}.conf` *and* `{PANEL_SLUG}-tls.conf`, PANEL_SLUG is
 * overridable, so every name-based guess breaks on a custom install. The disk
 * also knows about files this code has never heard of, which is most of them
 * on a server the panel was pointed at rather than installed alone on.
 *
 * Paths come from the real writers — {@see PoolManager::poolPath()} and the
 * driver's `configPath()` — reached through a throwaway model carrying the
 * proposed slug. A second copy of the path arithmetic would be free to drift
 * from the one that does the writing, and a probe of the wrong path is worse
 * than no probe: it reports "clear" about a file it never looked at.
 *
 * The question is put to each *directory* once rather than to each path —
 * see {@see SlugConflict::entries()} for why that distinction is the
 * difference between a guard and an outage.
 */
class SlugConflict
{
    public function __construct(
        private PoolManager $pools,
        private WebServerManager $webServers,
        private PhpVersionManager $versions,
        private ServerOps $serverOps,
    ) {}

    /**
     * What this slug would collide with, or null for nothing.
     *
     * **Null also means "could not find out".** A server whose sudo grant
     * predates this build answers no probe at all, and refusing every site
     * creation on a box that cannot be probed is far worse than the bug being
     * fixed — the failure mode would be "the panel cannot make sites any
     * more". Same reasoning as {@see PoolManager::exists()}, opposite default,
     * because that one reports and this one refuses.
     *
     * @return 'pool'|'vhost'|null
     */
    public function for(string $slug, ?Application $ignore = null): ?string
    {
        if ($slug === '') {
            return null;
        }

        // Renaming a site to the name it already has, or saving a form that
        // round-trips the name unchanged, must not trip over the site's own
        // files.
        if ($ignore !== null && (string) $ignore->slug === $slug) {
            return null;
        }

        $listings = [];

        foreach ($this->targetsFor($slug) as $kind => $targets) {
            foreach ($targets as $target) {
                if (in_array($target['entry'], $this->entries($target['dir'], $listings), true)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /**
     * The directories a site with this slug would put a file in, by what that
     * file would be.
     *
     * Pool directories are collected for **every installed PHP version**, not
     * just the default: the version is chosen per site and changed later from
     * the PHP screen, and a pool written under 8.3 collides with
     * `pool.d/www.conf` exactly as one written under 8.4 does.
     *
     * @return array<string, array<int, array{dir: string, entry: string}>>
     */
    private function targetsFor(string $slug): array
    {
        $probe = new Application;
        $probe->forceFill(['slug' => $slug]);

        $pools = [];

        foreach ($this->versions->versions() as $version) {
            $probe->forceFill(['php_version' => $version]);

            if (($path = $this->pools->poolPath($probe)) !== null) {
                $pools[] = ['dir' => dirname($path), 'entry' => basename($path)];
            }
        }

        $vhost = $this->webServers->driver()->configPath($probe);

        // OpenLiteSpeed's unit is a *directory* named after the slug, holding
        // the vhost file — so what gets claimed there, and what already exists
        // for the panel's own `panel` and `panel-api`, is one level up. On
        // nginx and Apache the file itself is the thing.
        $vhostTarget = basename(dirname($vhost)) === $slug
            ? ['dir' => dirname(dirname($vhost)), 'entry' => basename(dirname($vhost))]
            : ['dir' => dirname($vhost), 'entry' => basename($vhost)];

        return [
            'pool' => $this->unique($pools),
            'vhost' => [$vhostTarget],
        ];
    }

    /**
     * @param  array<int, array{dir: string, entry: string}>  $targets
     * @return array<int, array{dir: string, entry: string}>
     */
    private function unique(array $targets): array
    {
        $seen = [];

        foreach ($targets as $target) {
            $seen[$target['dir'].'/'.$target['entry']] = $target;
        }

        return array_values($seen);
    }

    /**
     * Read a directory once and look for the name in it.
     *
     * **Listing, rather than `test -e` on each candidate path.** The first
     * version asked the path directly, which is the obvious thing and was
     * wrong twice over: it cost one elevated call per PHP version plus one for
     * the vhost on every create, and — the part that mattered — an
     * indiscriminately successful probe reads as "every path exists", so
     * anything that answers yes to everything refuses every name a user could
     * type. That is not a hypothetical fake: it turned 39 existing create
     * tests into 422s in one run.
     *
     * A listing cannot fail that way. Nothing back means no entries, which
     * means no collision — the same answer an unreachable server gives, and
     * the safe one.
     *
     * That is also where the fail-open property actually lives. The
     * `answered && ok` test below reads like the thing holding the door open
     * and is not: removing it leaves `true ? parse : []`, and a refused `ls`
     * has no output to parse, so the answer is the empty list either way.
     * Measured — the sabotage is green. It is kept for what it says about
     * intent, not for what it does.
     *
     * @param  array<int, string>  $cache
     */
    private function entries(string $dir, array &$cache): array
    {
        if (! array_key_exists($dir, $cache)) {
            $result = $this->serverOps->probe(
                ['ls', '-1', $dir],
                ['feature' => 'application', 'op' => 'slug_conflict', 'dir' => $dir],
                timeout: 15,
            );

            $cache[$dir] = $result->answered && $result->ok
                ? array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $result->output()) ?: [])))
                : [];
        }

        return $cache[$dir];
    }
}
