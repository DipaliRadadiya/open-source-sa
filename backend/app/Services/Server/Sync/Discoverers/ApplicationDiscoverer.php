<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeDetector;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\OlsVhostLayout;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Sites the web server is already serving that the panel has no record of.
 *
 * The hard one, and the reason people migrate at all. A database is a name
 * from `SHOW DATABASES`; a site is a config file, a directory, an owner, and a
 * type that has to be read off the disk. Only the first three are facts.
 *
 * The type is a guess and is treated as one: every inference carries the
 * evidence behind it and a confidence, and both are stored on the application
 * as well as on the sync item. A site mislabelled WordPress is not a cosmetic
 * error — it is a site that later gets `wp` commands run against it.
 */
class ApplicationDiscoverer implements Discoverable
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private SiteTypeDetector $detector,
    ) {}

    public function resourceType(): string
    {
        return 'application';
    }

    public function dependsOn(): array
    {
        // A site needs an owner. Running before users means either inventing
        // one or attaching every site to whoever happens to exist.
        return ['system_user'];
    }

    public function discover(SyncRun $run): array
    {
        $command = $this->listCommand();

        if ($command === null) {
            return [];
        }

        $listing = $this->serverOps->run(
            $command,
            ['feature' => 'sync', 'op' => 'discover_applications'],
            timeout: 30,
        );

        if ($listing->failed()) {
            return [];
        }

        $trackedSlugs = Application::query()->pluck('slug')->filter()->map('strtolower')->all();
        $trackedDomains = ApplicationDomain::query()->pluck('domain')->map('strtolower')->all();
        $owners = SystemUser::query()->pluck('id', 'username');

        // The panel's own installation. Derived from where this code is
        // running rather than from a configured name: install.sh writes
        // `{PANEL_SLUG}.conf` *and* `{PANEL_SLUG}-tls.conf`, PANEL_SLUG is
        // overridable, and the vhost is named after the slug while the panel
        // is reached by a domain — so matching on any of those names is a
        // guess that a custom install breaks. The document root is not.
        $panelRoot = rtrim(dirname(base_path()), '/');
        $homeBase = rtrim((string) config('server.home_base', '/home'), '/');
        $excludedNames = (array) config('server.sync.exclude.vhosts', []);
        $excludedDomains = array_map('strtolower', (array) config('server.sync.exclude.domains', []));

        $found = [];

        foreach (preg_split('/\r?\n/', trim($listing->output())) ?: [] as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $name = $this->vhostName($path);

            if ($this->matchesAny($name, $excludedNames)) {
                continue;
            }

            if (in_array(strtolower($name), $trackedSlugs, true)) {
                continue;
            }

            $contents = $this->serverOps->run(
                ['cat', $path],
                ['feature' => 'sync', 'op' => 'read_vhost'],
                timeout: 30,
            );

            if ($contents->failed()) {
                $found[] = ['key' => $name, 'skip' => 'vhost_unreadable', 'evidence' => ['path' => $path]];

                continue;
            }

            $parsed = $this->parse($contents->output());

            if ($parsed['domains'] === [] || $parsed['root'] === null) {
                // A config this cannot read is reported rather than dropped:
                // the site is live, and a list that omits it is wrong in the
                // direction that matters.
                $found[] = ['key' => $name, 'skip' => 'vhost_unparsed', 'evidence' => ['path' => $path]];

                continue;
            }

            $primary = $parsed['domains'][0];

            if (in_array(strtolower($primary), $trackedDomains, true)) {
                continue;
            }

            if ($this->matchesAny(strtolower($primary), $excludedDomains)) {
                continue;
            }

            $root = rtrim($parsed['root'], '/');

            // The panel serving itself. Adopting it would put the control
            // plane in the list of sites the user can rename, move or delete
            // — and this catches the API vhost, the frontend vhost and the
            // TLS variant of each, without knowing any of their names.
            if ($root === $panelRoot || str_starts_with($root.'/', $panelRoot.'/')) {
                $found[] = [
                    'key' => $primary,
                    'skip' => 'panel_infrastructure',
                    'evidence' => ['path' => $path, 'document_root' => $root],
                ];

                continue;
            }

            // Every site this panel creates lives at
            // {home_base}/{user}/{slug}/public_html. A document root anywhere
            // else belongs to something the panel did not lay out and cannot
            // manage without moving files — reported so the user knows it
            // exists, rather than adopted into a shape that does not fit it.
            if (! str_starts_with($root.'/', $homeBase.'/')) {
                $found[] = [
                    'key' => $primary,
                    'skip' => 'outside_panel_layout',
                    'evidence' => ['path' => $path, 'document_root' => $root],
                ];

                continue;
            }

            $owner = $this->owner($parsed['root']);

            if ($owner === null || ! $owners->has($owner)) {
                // The owning account is not one the panel manages. Adopting
                // the site anyway would leave it with no user to run as, and
                // every later operation on it would fail somewhere deeper.
                $found[] = [
                    'key' => $primary,
                    'skip' => 'owner_not_tracked',
                    'evidence' => ['path' => $path, 'document_root' => $parsed['root'], 'owner' => $owner],
                ];

                continue;
            }

            $type = $this->detector->detectAt($parsed['root'], ['feature' => 'sync'])->toDiscoveryAttributes();

            $found[] = [
                'key' => $primary,
                'label' => $primary,
                'confidence' => $type['confidence'],
                'evidence' => [
                    'path' => $path,
                    'document_root' => $parsed['root'],
                    'owner' => $owner,
                    'domains' => $parsed['domains'],
                    'site_type' => $type['site_type'],
                    'matched' => $type['matched'],
                ],
                'attributes' => [
                    'system_user_id' => $owners->get($owner),
                    'domains' => $parsed['domains'],
                    'document_root' => $parsed['root'],
                    'site_type' => $type['site_type'],
                    'serving_profile' => $type['serving_profile'],
                    'confidence' => $type['confidence'],
                    'matched' => $type['matched'],
                ],
            ];
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];
        $domains = $attributes['domains'];
        $primary = $domains[0];

        $application = Application::forceCreate([
            'system_user_id' => $attributes['system_user_id'],
            'name' => Application::uniqueName($primary),
            // forceCreate and an explicit slug: `slug` is not fillable because
            // it names the config file the panel overwrites, and mass
            // assignment would drop it in silence.
            'slug' => Application::uniqueSlug($primary),
            'domain' => $primary,
            'site_type' => $attributes['site_type'],
            'serving_profile' => $attributes['serving_profile'],
            'status' => 'active',
            'web_root' => '/',
            // Deliberately null: the panel has not written a pool for this
            // site, and claiming otherwise would make `php:isolate-all` skip
            // the one site that most needs it.
            'isolated_at' => null,
            // The guess, kept where anyone acting on this site will see it.
            // The sync item has it too, but nobody looks up a sync run before
            // running a command against a site.
            'settings' => [
                'adoption' => [
                    'inferred_site_type' => $attributes['site_type'],
                    'confidence' => $attributes['confidence'],
                    'matched' => $attributes['matched'],
                    'document_root' => $attributes['document_root'],
                ],
            ],
        ]);

        foreach ($domains as $index => $domain) {
            ApplicationDomain::create([
                'application_id' => $application->id,
                'domain' => $domain,
                'type' => $index === 0 ? DomainType::Primary : DomainType::Alias,
                'is_test' => ApplicationDomain::looksTemporary($domain),
            ]);
        }

        return $application;
    }

    /**
     * How to list this web server's per-site configs.
     *
     * A command rather than a directory, because the layouts genuinely differ.
     * nginx and Apache keep one file per site in one directory. OpenLiteSpeed
     * keeps a *directory* per site, each holding a `vhconf.conf` — so a
     * `-maxdepth 1 -type f` over its vhost root finds nothing at all, which is
     * why sync used to return zero sites on this stack and call it a clean
     * result. A server with sites on it reporting none is the wrong answer to
     * give quietly.
     *
     * @return array<int, string>|null
     */
    private function listCommand(): ?array
    {
        $driver = $this->webServers->driver()->name();

        if ($driver === 'openlitespeed') {
            $layout = app(OlsVhostLayout::class);

            // Looked for here rather than read from the record, because this
            // is the one moment the panel is deliberately inspecting the box
            // and the only moment the answer can change: a server acquires the
            // old panel's directory by having had the old panel, which is true
            // before the first sync and never becomes true afterwards. Sync is
            // queued and already slow; two `find` calls are not what makes it
            // so.
            $root = $layout->detect();

            // Exactly two levels: <root>/<site>/<vhost file>. Deeper would pick
            // up the per-site logs directory the templates write beside it.
            //
            // Both filenames, because a server migrated from the old panel has
            // `main.conf` in a directory this panel did not choose. Matching
            // only our own name found zero sites on exactly the servers this
            // feature exists for, and reported it as a clean sync.
            return $root === '' ? null : array_merge(
                ['find', $root, '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '('],
                $layout->nameTests(),
                [')'],
            );
        }

        $directory = rtrim((string) config("server.web_server_drivers.{$driver}.sites_available_dir"), '/');

        return $directory === '' ? null : ['find', $directory, '-maxdepth', '1', '-type', 'f'];
    }

    /**
     * The site's name as this web server records it.
     *
     * For nginx and Apache that is the file. For OpenLiteSpeed the file has a
     * fixed name and the *directory* is the site, so the basename would give
     * every site on the box the same one — and the tracked-slug and exclusion
     * checks are both keyed on it.
     *
     * Checked against every name a vhost may have, not just ours. A server
     * migrated from the old panel calls it `main.conf`, and matching only
     * `vhconf.conf` meant those fell through to the filename branch and every
     * site on such a box was called `main` — the exact failure the paragraph
     * above describes, reintroduced one layout over.
     */
    private function vhostName(string $path): string
    {
        if (in_array(basename($path), OlsVhostLayout::FILENAMES, true)) {
            return basename(dirname($path));
        }

        return preg_replace('/\.conf$/', '', basename($path)) ?? basename($path);
    }

    /**
     * The names a vhost answers to and the directory it serves.
     *
     * Both syntaxes in one pass — nginx `server_name`/`root`, Apache
     * `ServerName`/`ServerAlias`/`DocumentRoot` — because which one is in
     * front is a per-server fact and the file itself says which it is.
     *
     * @return array{domains: array<int, string>, root: string|null}
     */
    private function parse(string $contents): array
    {
        $domains = [];

        // nginx: `server_name a b c;`
        if (preg_match_all('/^\s*server_name\s+([^;]+);/mi', $contents, $matches)) {
            foreach ($matches[1] as $group) {
                $domains = array_merge($domains, preg_split('/\s+/', trim($group)) ?: []);
            }
        }

        // Apache: ServerName one, ServerAlias many.
        if (preg_match_all('/^\s*Server(?:Name|Alias)\s+(.+)$/mi', $contents, $matches)) {
            foreach ($matches[1] as $group) {
                $domains = array_merge($domains, preg_split('/\s+/', trim($group)) ?: []);
            }
        }

        // OpenLiteSpeed: vhDomain and vhAliases, both comma-separated lists
        // rather than space-separated — split on either, since a config
        // written by hand may use spaces after the commas or instead of them.
        if (preg_match_all('/^\s*vh(?:Domain|Aliases)\s+(.+)$/mi', $contents, $matches)) {
            foreach ($matches[1] as $group) {
                $domains = array_merge($domains, preg_split('/[,\s]+/', trim($group)) ?: []);
            }
        }

        $domains = array_values(array_unique(array_filter(
            array_map('strtolower', array_map('trim', $domains)),
            // `_` is nginx's catch-all and names nothing; a wildcard is not a
            // domain the panel can own.
            fn (string $d): bool => $d !== '' && $d !== '_' && ! str_contains($d, '*'),
        )));

        $root = null;

        if (preg_match('/^\s*root\s+([^;]+);/mi', $contents, $m)) {
            $root = trim($m[1]);
        } elseif (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', $contents, $m)) {
            $root = trim($m[1]);
        } elseif (preg_match('/^\s*docRoot\s+"?([^"\s]+)"?/mi', $contents, $m)) {
            // OpenLiteSpeed. Matched last because `docRoot` is also a legal
            // Apache-ish spelling nobody uses, and nginx/Apache should keep
            // answering for their own files.
            $root = trim($m[1]);
        }

        return ['domains' => $domains, 'root' => $root];
    }

    /**
     * Case-insensitive match against a list that may contain `*` globs, so an
     * operator running their own stack alongside the panel can exclude it
     * with one line rather than one line per vhost.
     *
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch((string) $pattern, $value, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function owner(string $documentRoot): ?string
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%U', $documentRoot],
            ['feature' => 'sync', 'op' => 'stat_document_root'],
            timeout: 30,
        );

        return $result->failed() ? null : (trim($result->output()) ?: null);
    }
}
