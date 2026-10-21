<?php

namespace App\Services\Server\Applications;

use App\Contracts\WebServerDriver;
use App\Enums\SyncAction;
use App\Exceptions\Server\Application\FileRefusedException;
use App\Exceptions\Server\Application\SystemUserMissingException;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\SyncItem;
use App\Models\Worker;
use App\Services\ActivityLogger;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\VhostParser;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Log;

/**
 * What deleting a site that Server Sync adopted has to do about the config and
 * workers it was adopted *from* (FS-C22 #14).
 *
 * The panel's own delete knows only the files it writes — `{slug}.conf` and its
 * HTTPS siblings, `sv-worker-{slug}`. An adopted site is served from whatever
 * the server already had: `example.com.conf`, a v7 `-le-ssl` file, a program
 * in a shared supervisor file, a systemd unit. Left alone, those keep serving a
 * deleted site, or keep running inside a directory about to be removed.
 *
 * The rule throughout: **nothing is removed whose ownership the panel cannot
 * prove, and everything left behind is named.** The only record used is the
 * one Sync wrote at the time (`sync_items`, with the exact path it read); the
 * server is never searched for a likely match, because a guess is how an
 * operator's own vhost gets deleted.
 *
 *  - a vhost file is removed only if it is a plain file at the recorded path,
 *    still serves exactly this site's root and only this site's domains, is
 *    not another site's file, and its enabled entry is a link to it (or an
 *    identical copy). Then the web server's config test must pass, or every
 *    file and link is put back exactly as it was;
 *  - an OpenLiteSpeed vhost under another name lives in the server-wide
 *    `httpd_config.conf`, outside the panel's markers: reported, not edited;
 *  - a worker adopted from a shared file or a systemd unit is never stopped,
 *    disabled or edited. Deleting the site *with its files* is refused while
 *    one exists ({@see refuseUnprovenWorkers()}), because the files are what
 *    it runs from; without the files it is reported.
 */
class AdoptedSiteConfig
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private WebServerManager $webServers,
        private VhostParser $parser,
        private ActivityLogger $activity,
    ) {}

    /**
     * Refuse "delete with files" while an adopted worker the panel cannot
     * prove it owns still runs from this site — before anything is changed.
     *
     * Read-only. Called first thing by the delete endpoint, ahead of every
     * removal, the backups, the database drops and the activity entry, so a
     * refusal means the site, its record and every service are exactly as
     * they were. Nothing here stops or edits the worker: it may be somebody
     * else's, and that is the user's call on their own server.
     *
     * Counted:
     *  - an adopted worker with no canonical `program` — it came from a
     *    shared supervisor file or a systemd unit. An unset `program` is never
     *    read as "safe": it is the panel's own only when Sync never adopted it;
     *  - an adopted canonical `program` whose file no longer proves it is that
     *    worker's alone ({@see WorkerSupervisor::unprovableProgram()}).
     *
     * @throws FileRefusedException 422 `adopted_worker_unproven`
     */
    public function refuseUnprovenWorkers(Application $application): void
    {
        // Lazily, for the container-cycle reason ApplicationArtifacts gives.
        $supervisor = app(WorkerSupervisor::class);
        $unproven = [];

        foreach (Worker::where('application_id', $application->id)->orderBy('id')->get() as $worker) {
            if ($worker->hasProgram()) {
                if ($supervisor->unprovableProgram($worker) !== null) {
                    $unproven[] = ['worker' => $worker, 'path' => $supervisor->configPath($worker)];
                }

                continue;
            }

            $evidence = $this->workerEvidence($worker);

            if ($evidence !== null) {
                $unproven[] = ['worker' => $worker, 'path' => $this->sourcePath($evidence)];
            }
        }

        if ($unproven === []) {
            return;
        }

        Log::channel('server-ops')->warning('site deletion with files refused: an adopted worker still runs from it', [
            'feature' => 'application',
            'op' => 'delete_refused',
            'application' => $application->id,
            'workers' => array_map(fn (array $u): array => ['worker' => $u['worker']->id, 'path' => $u['path']], $unproven),
        ]);

        // The name and the config file it lives in, so the user can find it.
        // Never the command line: an adopted one can carry a password.
        throw FileRefusedException::because('remove_files', 'adopted_worker_unproven', 'errors/application.delete_files_adopted_worker', [
            'workers' => implode(', ', array_map(fn (array $u): string => '"'.$u['worker']->name.'" ('.$u['path'].')', $unproven)),
        ]);
    }

    /**
     * Name the original of an adopted worker that the delete leaves running:
     * a program in a shared supervisor file, or a systemd unit. The panel's
     * own `sv-worker-{slug}` program is still removed as before; this is the
     * one Sync found, which it never controlled.
     */
    public function reportWorkerSource(Application $application, Worker $worker): void
    {
        if ($worker->hasProgram()) {
            return;
        }

        $evidence = $this->workerEvidence($worker);

        if ($evidence === null) {
            return;
        }

        $this->leftover($application, 'adopted_worker', $this->sourcePath($evidence),
            ($evidence['source'] ?? null) === 'systemd' ? 'worker_systemd' : 'worker_shared');
    }

    /** An adopted program file that was left alone because it no longer proved itself. */
    public function reportWorker(Application $application, string $path, string $reason): void
    {
        $this->leftover($application, 'adopted_worker', $path, $reason);
    }

    /**
     * Remove the vhost files Sync adopted this site from, when — and only
     * when — they can be shown to be this site's alone.
     *
     * Runs from {@see ApplicationArtifacts}, before the driver removes the
     * slug files and before any `rm -rf` of the site.
     */
    public function remove(Application $application): void
    {
        $item = $this->adoptedItem($application);

        if ($item === null) {
            // Adopted, but the record of where from is gone. There is nothing
            // to remove that is not a guess, so nothing is — said in the log,
            // which is all there is to say without a path.
            if (isset($application->settings['adoption'])) {
                Log::channel('server-ops')->warning('adopted site config unknown: no sync record of where it was found', [
                    'feature' => 'application',
                    'op' => 'deprovision_artifact',
                    'artifact' => 'adopted_vhost',
                    'application' => $application->id,
                    'reason' => 'config_unknown',
                ]);
            }

            return;
        }

        $evidence = (array) $item->evidence;
        $driver = $this->webServers->driver();

        if ($driver->name() === 'openlitespeed') {
            $this->reportOls($application, $driver, $evidence['path'] ?? null);

            return;
        }

        if (! in_array($driver->name(), ['nginx', 'apache'], true)) {
            return;
        }

        $recordedRoots = array_values(array_filter([
            is_string($evidence['document_root'] ?? null) ? rtrim($evidence['document_root'], '/') : null,
            is_string($application->settings['adoption']['document_root'] ?? null) ? rtrim($application->settings['adoption']['document_root'], '/') : null,
        ]));

        $paths = [$evidence['path'] ?? null, ...$this->siblingPaths($application, $item)];
        $removable = [];

        foreach (array_unique(array_map(fn (mixed $p): string => is_string($p) && $p !== '' ? $p : '?', $paths)) as $path) {
            $verdict = $this->inspect($application, $driver, $path, $recordedRoots);

            if (is_string($verdict)) {
                $this->leftover($application, 'adopted_vhost', $path, $verdict);
            } elseif ($verdict !== null) {
                $removable[] = $verdict;
            }
        }

        if ($removable !== []) {
            $this->removeVerified($application, $driver, $removable);
        }
    }

    /**
     * The newest record of Sync adopting this site, which holds the exact
     * vhost path it read.
     */
    private function adoptedItem(Application $application): ?SyncItem
    {
        return SyncItem::query()
            ->where('model_type', Application::class)
            ->where('model_id', $application->id)
            ->where('resource_type', 'application')
            ->where('action', SyncAction::Adopted)
            ->latest('id')
            ->first();
    }

    /**
     * The v7 HTTPS file and any other vhost Sync found for the same folder in
     * the same run. It was skipped as `folder_taken` because the first file
     * already claimed the folder, so it is linked to nothing — but it serves
     * this site. Each one is verified on its own merits, like the first.
     *
     * @return array<int, string>
     */
    private function siblingPaths(Application $application, SyncItem $item): array
    {
        return SyncItem::query()
            ->where('sync_run_id', $item->sync_run_id)
            ->where('resource_type', 'application')
            ->where('action', SyncAction::Skipped)
            ->where('reason', 'folder_taken')
            ->orderBy('id')
            ->get()
            ->filter(fn (SyncItem $sibling): bool => ($sibling->evidence['folder'] ?? null) === $application->slug
                && is_string($sibling->evidence['path'] ?? null))
            ->map(fn (SyncItem $sibling): string => $sibling->evidence['path'])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function workerEvidence(Worker $worker): ?array
    {
        $item = SyncItem::query()
            ->where('model_type', Worker::class)
            ->where('model_id', $worker->id)
            ->where('resource_type', 'worker')
            ->where('action', SyncAction::Adopted)
            ->latest('id')
            ->first();

        return $item === null ? null : (array) $item->evidence;
    }

    /** @param array<string, mixed> $evidence */
    private function sourcePath(array $evidence): string
    {
        $path = $evidence['path'] ?? $evidence['unit'] ?? null;

        return is_string($path) && $path !== '' ? $path : '?';
    }

    /**
     * One recorded vhost path: null when there is nothing to do (gone, or the
     * driver's own file), a reason string when it must be left, otherwise
     * what removing it — and putting it back — needs.
     *
     * @param  array<int, string>  $recordedRoots
     * @return array{path: string, contents: string, link: array{kind: string, path: string, target?: string, contents?: string}|null}|string|null
     */
    private function inspect(Application $application, WebServerDriver $driver, string $path, array $recordedRoots): array|string|null
    {
        $available = rtrim((string) config("server.web_server_drivers.{$driver->name()}.sites_available_dir"), '/');
        $enabled = rtrim((string) config("server.web_server_drivers.{$driver->name()}.sites_dir"), '/');
        $base = basename($path);

        // Exactly one name inside sites-available, spelt as the web server
        // reads it. Anything else — another directory, `..`, a doubled slash —
        // is not a path the panel would ever have recorded.
        $pattern = $driver->name() === 'apache'
            ? '/^[A-Za-z0-9][A-Za-z0-9._-]*\.conf$/D'
            : '/^[A-Za-z0-9][A-Za-z0-9._-]*(\.conf)?$/D';

        if ($available === '' || $enabled === '' || $path !== $available.'/'.$base || preg_match($pattern, $base) !== 1) {
            return 'unexpected_path';
        }

        // The driver removes the site's own files anyway; never twice.
        if (in_array($base, $this->slugFiles((string) $application->slug), true)) {
            return null;
        }

        // Another site's own file, or a name the operator excluded from Sync.
        $others = Application::query()->whereKeyNot($application->id)->pluck('slug')->filter()->all();

        foreach ($others as $slug) {
            if (in_array($base, $this->slugFiles((string) $slug), true)) {
                return 'other_site';
            }
        }

        foreach ((array) config('server.sync.exclude.vhosts', []) as $excluded) {
            if (fnmatch((string) $excluded, (string) preg_replace('/\.conf$/', '', $base), FNM_CASEFOLD)) {
                return 'other_site';
            }
        }

        $entry = $this->entry($available, $base, $application);

        if ($entry === false) {
            return 'unreadable';
        }

        if ($entry === null) {
            return null;
        }

        if ($entry['type'] !== 'f') {
            return 'not_regular';
        }

        $read = $this->files->get($path, $this->context($application));

        if ($read->failed()) {
            return 'unreadable';
        }

        $contents = $read->output();
        $owned = $this->ownership($application, $this->parser->parse($contents), $recordedRoots);

        if ($owned !== null) {
            return $owned;
        }

        $link = $this->entry($enabled, $base, $application);

        if ($link === false) {
            return 'unreadable';
        }

        if ($link === null) {
            return ['path' => $path, 'contents' => $contents, 'link' => null];
        }

        $linkPath = $enabled.'/'.$base;

        if ($link['type'] === 'l') {
            // a2ensite writes `../sites-available/<name>`; by hand it is
            // usually the absolute path. Both name this file and nothing else.
            $relative = dirname($enabled) === dirname($available) ? '../'.basename($available).'/'.$base : null;

            return $link['target'] === $path || ($relative !== null && $link['target'] === $relative)
                ? ['path' => $path, 'contents' => $contents, 'link' => ['kind' => 'symlink', 'path' => $linkPath, 'target' => $link['target']]]
                : 'foreign_link';
        }

        if ($link['type'] === 'f') {
            $copy = $this->files->get($linkPath, $this->context($application));

            if ($copy->failed()) {
                return 'unreadable';
            }

            return hash_equals(hash('sha256', $contents), hash('sha256', $copy->output()))
                ? ['path' => $path, 'contents' => $contents, 'link' => ['kind' => 'copy', 'path' => $linkPath, 'contents' => $copy->output()]]
                : 'foreign_link';
        }

        return 'foreign_link';
    }

    /**
     * Does this config still serve this site and nothing else?
     *
     * @param  array{domains: array<int, string>, roots: array<int, string>, unowned_names: array<int, string>}  $parsed
     * @param  array<int, string>  $recordedRoots
     */
    private function ownership(Application $application, array $parsed, array $recordedRoots): ?string
    {
        // A catch-all, a wildcard, or a second directory: shared by
        // construction, whoever wrote it.
        if ($parsed['domains'] === [] || $parsed['unowned_names'] !== [] || count($parsed['roots']) !== 1) {
            return 'not_this_site';
        }

        $mine = $application->domains()->pluck('domain')->push((string) $application->domain)
            ->map(fn (string $d): string => strtolower($d))->all();
        $theirs = ApplicationDomain::query()->where('application_id', '!=', $application->id)->pluck('domain')
            ->merge(Application::query()->whereKeyNot($application->id)->pluck('domain'))
            ->filter()->map(fn (string $d): string => strtolower($d))->all();

        foreach ($parsed['domains'] as $domain) {
            if (in_array($domain, $theirs, true)) {
                return 'other_site';
            }

            if (! in_array($domain, $mine, true)) {
                return 'not_this_site';
            }
        }

        $root = $parsed['roots'][0];

        try {
            $siteRoot = rtrim($application->rootPath(), '/');
            $computed = rtrim($application->documentRoot(), '/');
        } catch (SystemUserMissingException) {
            return 'not_this_site';
        }

        if ($siteRoot === '' || str_contains($root.'/', '/../') || str_contains($root.'/', '/./')
            || ! in_array($root, [...$recordedRoots, $computed], true)
            || ($root !== $siteRoot && ! str_starts_with($root, $siteRoot.'/'))) {
            return 'not_this_site';
        }

        // The panel itself — the same rule discovery applies.
        $panel = rtrim(dirname(base_path()), '/');

        if ($root === $panel || str_starts_with($root.'/', $panel.'/')) {
            return 'other_site';
        }

        // Another site living inside this one's folder, or in the same one.
        foreach (Application::query()->whereKeyNot($application->id)->with('systemUser')->get() as $other) {
            try {
                $otherRoot = rtrim($other->rootPath(), '/');
            } catch (SystemUserMissingException) {
                continue;
            }

            if ($otherRoot === $siteRoot || str_starts_with($otherRoot.'/', $siteRoot.'/')) {
                return 'other_site';
            }
        }

        return null;
    }

    /**
     * Remove every verified file and its link, then prove the web server
     * still accepts its config. Any failure puts all of them back.
     *
     * @param  array<int, array{path: string, contents: string, link: array{kind: string, path: string, target?: string, contents?: string}|null}>  $removable
     */
    private function removeVerified(Application $application, WebServerDriver $driver, array $removable): void
    {
        $context = $this->context($application);
        $removed = [];
        $reason = null;

        foreach ($removable as $plan) {
            // The link before its file, so the web server never reads a link
            // to nothing.
            foreach ([$plan['link'], ['kind' => 'file', 'path' => $plan['path'], 'contents' => $plan['contents']]] as $entry) {
                if ($entry === null) {
                    continue;
                }

                if ($this->files->delete($entry['path'], $context)->failed()) {
                    $reason = 'remove_failed';

                    break 2;
                }

                $removed[] = $entry;
            }
        }

        if ($reason === null && $driver->test()->failed()) {
            $reason = 'config_test_failed';
        }

        if ($reason === null) {
            Log::channel('server-ops')->info('adopted site config removed', [
                'feature' => 'application',
                'op' => 'deprovision_artifact',
                'artifact' => 'adopted_vhost',
                'application' => $application->id,
                'paths' => array_column($removable, 'path'),
            ]);

            return;
        }

        // Back in reverse: each file before the link that points at it, each
        // exactly as it was — same link target, same bytes. Only into the web
        // server's own directories, which only root can write.
        $restored = true;

        foreach (array_reverse($removed) as $entry) {
            $result = $entry['kind'] === 'symlink'
                ? $this->files->symlink((string) $entry['target'], $entry['path'], $context)
                : $this->files->put($entry['path'], (string) $entry['contents'], $context);

            $restored = $restored && $result->ok;
        }

        foreach ($removable as $plan) {
            $this->leftover($application, 'adopted_vhost', $plan['path'], $restored ? $reason : 'restore_failed');
        }
    }

    /**
     * OpenLiteSpeed: the panel removes `<root>/{slug}/`. A vhost adopted under
     * another directory name is registered in the server-wide
     * `httpd_config.conf` outside the panel's markers, which every site on the
     * server depends on — so it is reported rather than edited.
     */
    private function reportOls(Application $application, WebServerDriver $driver, mixed $path): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '/../')) {
            $this->leftover($application, 'adopted_vhost', is_string($path) ? $path : '?', 'unexpected_path');

            return;
        }

        if (dirname($path) === dirname($driver->configPath($application))) {
            return;
        }

        $entry = $this->entry(dirname($path), basename($path), $application);

        if ($entry !== null) {
            $this->leftover($application, 'adopted_vhost', $path, 'ols_shared');
        }
    }

    /**
     * What sits at `<directory>/<name>`, without following it: null when
     * nothing does, false when the directory could not be read.
     *
     * The directory is listed rather than the path tested, so "not there" is
     * an empty answer — not an error indistinguishable from a refused grant.
     *
     * @return array{type: string, target: string}|false|null
     */
    private function entry(string $directory, string $name, Application $application): array|false|null
    {
        $result = $this->serverOps->run(
            ['find', '-P', $directory, '-mindepth', '1', '-maxdepth', '1', '-name', $name, '-printf', '%y\t%l\n'],
            $this->context($application),
        );

        if ($result->failed()) {
            return false;
        }

        $lines = array_values(array_filter(preg_split('/\r?\n/', $result->output()) ?: [], fn (string $l): bool => $l !== ''));

        if ($lines === []) {
            return null;
        }

        if (count($lines) !== 1 || ! str_contains($lines[0], "\t")) {
            return false;
        }

        [$type, $target] = explode("\t", $lines[0], 2);

        return ['type' => $type, 'target' => $target];
    }

    /**
     * The files the driver writes for a slug — `{slug}.conf` and both HTTPS
     * names (v7's layout).
     *
     * @return array<int, string>
     */
    private function slugFiles(string $slug): array
    {
        return $slug === '' ? [] : ["{$slug}.conf", "{$slug}-le-ssl.conf", "{$slug}-ssl.conf"];
    }

    /**
     * Named in the server-ops log and in the activity log, which is where a
     * user looking at a deleted site will see it. Paths only, never contents.
     */
    private function leftover(Application $application, string $artifact, string $path, string $reason): void
    {
        Log::channel('server-ops')->{$reason === 'restore_failed' ? 'error' : 'warning'}('adopted site config left in place', [
            'feature' => 'application',
            'op' => 'deprovision_artifact',
            'artifact' => $artifact,
            'application' => $application->id,
            'path' => $path,
            'reason' => $reason,
        ]);

        $this->activity->log('application.adopted_config_left', $application, [
            'name' => $application->name,
            'path' => $path,
            'left_reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> */
    private function context(Application $application): array
    {
        return ['feature' => 'application', 'op' => 'remove_adopted_config', 'application' => $application->id];
    }
}
