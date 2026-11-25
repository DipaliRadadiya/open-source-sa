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
 * The slug becomes a filename in the PHP-FPM pool directory, the web server's sites directory and
 * (on OpenLiteSpeed) the vhost directory. Uniqueness in the `applications` table knows nothing
 * about what is already on disk, so:
 *
 *  - a site named **www** would take `pool.d/www.conf`, the distro pool every non-isolated PHP
 *    site uses;
 *  - a site named **panel** would take `sites-available/panel.conf`, the panel's own vhost.
 *
 * Both are reachable from create and from rename, since a rename regenerates the slug
 * ({@see UpdateApplication}).
 *
 * Asked of the disk, not a reserved-word list: `PANEL_SLUG` is overridable and the disk also holds
 * files this code has never heard of. Paths come from the real writers
 * ({@see PoolManager::poolPath()} and the driver's `configPath()`) via a throwaway model, so the
 * path arithmetic cannot drift. Each *directory* is listed once, not each path; see
 * {@see SlugConflict::entries()}.
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
     * Null also means "could not find out": refusing every site on a box that cannot be probed
     * would be worse (opposite default to {@see PoolManager::exists()}, which only reports).
     *
     * @return 'pool'|'vhost'|null
     */
    public function for(string $slug, ?Application $ignore = null): ?string
    {
        if ($slug === '') {
            return null;
        }

        // An unchanged name must not trip over the site's own files.
        if ($ignore !== null && (string) $ignore->slug === $slug) {
            return null;
        }

        if ($this->sortsBeforeDefault($slug)) {
            return 'vhost_order';
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
     * Apache answers an unknown name with the first vhost loaded in file-name order, which is
     * v7's `000-default.conf` / `000-default-ssl.conf`; a site sorting before them (`00-shop.conf`)
     * would answer for every unknown name. nginx and OpenLiteSpeed choose differently, so only
     * Apache is asked.
     */
    private function sortsBeforeDefault(string $slug): bool
    {
        if ($this->webServers->driver()->name() !== 'apache') {
            return false;
        }

        $main = basename((string) config('server.web_server_drivers.apache.catch_all'));
        $ssl = basename((string) config('server.web_server_drivers.apache.catch_all_ssl'));

        return ($main !== '' && strcmp($slug.'.conf', $main) < 0)
            || ($ssl !== '' && (strcmp($slug.'-le-ssl.conf', $ssl) < 0 || strcmp($slug.'-ssl.conf', $ssl) < 0));
    }

    /**
     * The directories a site with this slug would put a file in, by what that file would be.
     *
     * Pool directories are collected for every installed PHP version: the version is chosen per
     * site and can change later.
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

        // OpenLiteSpeed's unit is a directory named after the slug, so the claim is one level up;
        // on nginx and Apache it is the file itself.
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
     * A listing, not `test -e` per path: that costs one elevated call per PHP version, and a probe
     * that answers yes to everything would refuse every name. A listing with nothing back means no
     * entries, so no collision (fail open, same as an unreachable server).
     *
     * @param  array<int, string>  $cache
     */
    private function entries(string $dir, array &$cache): array
    {
        if (! array_key_exists($dir, $cache)) {
            // `find`, not `ls`: only allowlisted binaries run under sudo, and unelevated `ls` cannot
            // read OpenLiteSpeed's 0750 vhosts directory, which read as empty and let `panel`
            // take over the panel's own vhost.
            $result = $this->serverOps->probe(
                // `-H`: follow the directory itself if it is a symlink, else nothing is listed.
                ['find', '-H', $dir, '-mindepth', '1', '-maxdepth', '1', '-printf', '%f\n'],
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
