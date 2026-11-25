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
use App\Services\Server\WebServers\VhostParser;
use App\Services\Server\WebServers\WebServerManager;
use RuntimeException;

/**
 * Sites the web server is already serving that the panel has no record of.
 *
 * A site is a config file, a directory, an owner (facts) and a type read off the disk (a
 * guess). Every inference carries its evidence and a confidence, stored on the application
 * and on the sync item: a site mislabelled WordPress later gets `wp` commands run against it.
 */
class ApplicationDiscoverer implements Discoverable
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private SiteTypeDetector $detector,
        private VhostParser $parser,
    ) {}

    public function resourceType(): string
    {
        return 'application';
    }

    public function dependsOn(): array
    {
        // A site needs an owner.
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
        $homes = SystemUser::query()->pluck('home_path', 'username');

        // In a preview, the accounts it found count as owners too (applying adopts them first).
        // No id is needed: a preview never adopts.
        foreach ($run->previewedSystemUsers() as $username => $home) {
            if (! $owners->has($username)) {
                $owners->put($username, null);
                $homes->put($username, $home);
            }
        }

        // Folders claimed in this run, so two vhosts serving one directory are not both adopted.
        $claimed = [];

        // The panel's own installation, derived from where this code runs: vhost names depend
        // on the overridable PANEL_SLUG (and a `-tls` variant), the document root does not.
        $panelRoot = rtrim(dirname(base_path()), '/');
        $homeBase = rtrim((string) config('server.home_base', '/home'), '/');
        $excludedNames = (array) config('server.sync.exclude.vhosts', []);
        $excludedDomains = array_map('strtolower', (array) config('server.sync.exclude.domains', []));

        $found = [];
        $enabled = $this->enabledVhosts();

        foreach (preg_split('/\r?\n/', trim($listing->output())) ?: [] as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            // Not enabled is not served (nginx's stock `default` sits unlinked in sites-available).
            if ($enabled !== null && ! in_array(basename($path), $enabled, true)) {
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

            // OpenLiteSpeed's installer ships a demo vhost `Example` (docRoot $VH_ROOT/html/): not a
            // customer's site. Recognised by name and stock document root, not by registration in
            // httpd_config.conf (a migrated server registers every site through an `include`).
            if ($name === 'Example' && preg_match('/^\s*docRoot\s+\$VH_ROOT\/html\/?\s*$/m', $contents->output()) === 1) {
                continue;
            }

            // Shared with deleting an adopted site, so both read a file the same
            // way ({@see VhostParser}).
            $parsed = $this->parser->parse($contents->output());

            if ($parsed['domains'] === [] || $parsed['root'] === null) {
                // Reported rather than dropped: the site is live.
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

            // The panel serving itself (API, frontend and TLS variants): adopting it would let the
            // user rename, move or delete the control plane.
            if ($root === $panelRoot || str_starts_with($root.'/', $panelRoot.'/')) {
                $found[] = [
                    'key' => $primary,
                    'skip' => 'panel_infrastructure',
                    'evidence' => ['path' => $path, 'document_root' => $root],
                ];

                continue;
            }

            // Panel sites live at {home_base}/{user}/{slug}/public_html; a root anywhere else
            // cannot be managed without moving files, so it is reported, not adopted.
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
                // The owner is not managed by the panel; adopting would leave no user to run as.
                $found[] = [
                    'key' => $primary,
                    'skip' => 'owner_not_tracked',
                    'evidence' => ['path' => $path, 'document_root' => $parsed['root'], 'owner' => $owner],
                ];

                continue;
            }

            // The panel addresses a site as {home}/{slug}/public_html/{web_root}, so the slug must
            // be the folder the files are in, not a name made from the domain.
            $layout = $this->layout($parsed['root'], rtrim((string) $homes->get($owner), '/'));

            if ($layout === null) {
                $found[] = [
                    'key' => $primary,
                    'skip' => 'outside_panel_layout',
                    'evidence' => ['path' => $path, 'document_root' => $parsed['root'], 'owner' => $owner],
                ];

                continue;
            }

            $folder = $layout['slug'];

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $folder) !== 1) {
                $found[] = [
                    'key' => $primary,
                    'skip' => 'folder_name_unusable',
                    'evidence' => ['path' => $path, 'document_root' => $parsed['root'], 'folder' => $folder],
                ];

                continue;
            }

            // The slug is unique across the panel and names files it writes. Two owners can each
            // have a `shop` folder; the second is reported, not adopted under a name that
            // would not match its files.
            if (in_array(strtolower($folder), $trackedSlugs, true) || isset($claimed[strtolower($folder)])) {
                $found[] = [
                    'key' => $primary,
                    'skip' => 'folder_taken',
                    'evidence' => ['path' => $path, 'document_root' => $parsed['root'], 'folder' => $folder],
                ];

                continue;
            }

            $claimed[strtolower($folder)] = true;

            $type = $this->detector->detectAt($parsed['root'], ['feature' => 'sync'])->toDiscoveryAttributes();

            $found[] = [
                'key' => $primary,
                'label' => $primary,
                'confidence' => $type['confidence'],
                'evidence' => [
                    'path' => $path,
                    'document_root' => $parsed['root'],
                    'owner' => $owner,
                    'folder' => $folder,
                    'domains' => $parsed['domains'],
                    'site_type' => $type['site_type'],
                    'matched' => $type['matched'],
                    'proxied_port' => $parsed['port'],
                ],
                'attributes' => [
                    'system_user_id' => $owners->get($owner),
                    'domains' => $parsed['domains'],
                    'slug' => $folder,
                    'web_root' => $layout['web_root'],
                    'document_root' => $parsed['root'],
                    'app_port' => $parsed['port'],
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

        // The folder the files are in (see layout()), checked again in case a site created since
        // the preview took it.
        //
        // Deliberately NOT passed {@see \App\Services\Server\Applications\SlugConflict}: it asks
        // whether a file at the slug's paths exists, which for an adopted site is true by
        // definition and would refuse every adoption. Residual case: a folder like `~/www` adopts
        // as slug `www`; left alone because the pool writer takes over unowned pool files on
        // purpose (see `PoolManager::adoptOpenBasedir()`).
        $slug = (string) $attributes['slug'];

        if (Application::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException("the folder {$slug} is already a site in the panel");
        }

        // `applications.app_port` is unique: a port already claimed in this run is dropped to
        // null rather than failing the whole adoption.
        $port = $attributes['app_port'] ?? null;

        if ($port !== null && Application::query()->where('app_port', $port)->exists()) {
            $port = null;
        }

        $application = Application::forceCreate([
            'system_user_id' => $attributes['system_user_id'],
            'name' => Application::uniqueName($primary),
            // forceCreate: `slug` is not fillable (it names the config file the panel overwrites).
            'slug' => $slug,
            'domain' => $primary,
            'site_type' => $attributes['site_type'],
            'serving_profile' => $attributes['serving_profile'],
            'status' => 'active',
            'web_root' => $attributes['web_root'] ?? '/',
            // Read off `proxy_pass`: the vhost is the only record of a migrated Node app's port.
            'app_port' => $port,
            // Deliberately null: no pool was written for this site, and claiming otherwise would
            // make `php:isolate-all` skip it.
            'isolated_at' => null,
            // The guess, kept where anyone acting on this site will see it.
            'settings' => [
                'adoption' => [
                    'inferred_site_type' => $attributes['site_type'],
                    'confidence' => $attributes['confidence'],
                    'matched' => $attributes['matched'],
                    'document_root' => $attributes['document_root'],
                    'proxied_port' => $attributes['app_port'] ?? null,
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
     * A command rather than a directory, because the layouts differ: nginx and Apache keep
     * one file per site, OpenLiteSpeed a *directory* per site holding a `vhconf.conf`.
     *
     * @return array<int, string>|null
     */
    private function listCommand(): ?array
    {
        $driver = $this->webServers->driver()->name();

        if ($driver === 'openlitespeed') {
            $layout = app(OlsVhostLayout::class);

            // Looked for here, not read from the record: this is the one moment the box is
            // deliberately inspected, and a server only has the old panel's directory from
            // before the first sync.
            $root = $layout->detect();

            // Exactly two levels: <root>/<site>/<vhost file> (deeper picks up the per-site logs
            // directory). Both filenames, because a server migrated from the old panel has
            // `main.conf` in a directory this panel did not choose.
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
     * The vhost files the web server actually reads, by name (its sites-enabled directory),
     * for nginx and Apache. Null where that does not apply (OpenLiteSpeed) or the directory
     * could not be listed; then nothing is filtered, so an unreadable directory does not make
     * every site look unserved.
     *
     * @return array<int, string>|null
     */
    private function enabledVhosts(): ?array
    {
        // The same as listCommand(): the detected web server, not the (possibly unset) configured one.
        $driver = $this->webServers->driver()->name();

        if (! in_array($driver, ['nginx', 'apache'], true)) {
            return null;
        }

        $directory = rtrim((string) config("server.web_server_drivers.{$driver}.sites_dir"), '/');

        if ($directory === '') {
            return null;
        }

        $result = $this->serverOps->run(
            ['find', $directory, '-maxdepth', '1', '(', '-type', 'l', '-o', '-type', 'f', ')', '-printf', '%f\\n'],
            ['feature' => 'sync', 'op' => 'discover_enabled_vhosts'],
            timeout: 30,
        );

        return $result->failed()
            ? null
            : array_values(array_filter(array_map(fn (string $line) => basename(trim($line)), preg_split('/\r?\n/', trim($result->output())) ?: [])));
    }

    /**
     * The site's name as this web server records it.
     *
     * For nginx and Apache that is the file. For OpenLiteSpeed the file has a fixed name and the
     * *directory* is the site (the tracked-slug and exclusion checks are keyed on it). Checked
     * against every name a vhost may have: a server migrated from the old panel calls it
     * `main.conf`.
     */
    private function vhostName(string $path): string
    {
        if (in_array(basename($path), OlsVhostLayout::FILENAMES, true)) {
            return basename(dirname($path));
        }

        return preg_replace('/\.conf$/', '', basename($path)) ?? basename($path);
    }

    /**
     * The panel's view of a document root: the folder under the owner's home
     * (the slug) and the path inside its public_html (the web root).
     *
     * Null when the root is not `{home}/{folder}/public_html[/...]`, which is
     * the only shape the panel can manage without moving files.
     *
     * @return array{slug: string, web_root: string}|null
     */
    private function layout(string $root, string $home): ?array
    {
        $root = rtrim($root, '/');

        if ($home === '' || ! str_starts_with($root.'/', $home.'/')) {
            return null;
        }

        $parts = explode('/', substr($root, strlen($home) + 1));

        if (count($parts) < 2 || $parts[0] === '' || $parts[1] !== 'public_html') {
            return null;
        }

        $inside = array_slice($parts, 2);

        if (in_array('..', $inside, true) || in_array('.', $inside, true)) {
            return null;
        }

        return [
            'slug' => $parts[0],
            'web_root' => $inside === [] ? '/' : implode('/', $inside),
        ];
    }

    /**
     * Case-insensitive match against a list that may contain `*` globs.
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
