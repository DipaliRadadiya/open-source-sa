<?php

namespace App\Services\Panel;

/**
 * Moving a live, working panel from one checkout to the release layout.
 *
 * This runs on a server that is currently fine, so the shape is chosen for what happens
 * when it goes wrong.
 *
 * **Everything is a move, never a copy.** The existing checkout becomes the first release,
 * with its `vendor/` and `.next/`: no rebuild and no second copy of the tree.
 *
 * **The old paths keep working.** `<root>/backend` and `<root>/frontend` survive as symlinks
 * into `current/`. The checkout path is baked into panel-frontend.service,
 * panel-queue.service, the php-fpm pool, the schedule cron line and the web-server vhost;
 * rewriting them on a working server is the likeliest way to take it down.
 *
 * Planning only: this class writes nothing. {@see \App\Console\Commands\
 * MigratePanelLayout} executes, and dry-runs by default, so the operator reads the same
 * list that runs.
 */
class PanelMigration
{
    /** Directories that are the new layout, not content to be moved into it. */
    private const LAYOUT_DIRS = ['releases', 'shared', 'current'];

    public function __construct(
        private PanelLayout $layout,
        private PanelReleases $releases,
        private PanelPhpBinary $phpBinary,
    ) {}

    /**
     * Reasons this server must not be migrated, as translation keys.
     *
     * Checked before anything moves: a problem found halfway leaves a panel in neither shape.
     *
     * @return array<int, string>
     */
    public function preflight(): array
    {
        $problems = [];
        $root = $this->layout->root();

        if ($this->layout->isReleased()) {
            $problems[] = 'already_migrated';
        }

        if (! is_dir($root.'/backend') || ! is_dir($root.'/frontend')) {
            $problems[] = 'not_a_checkout';
        }

        if (! is_dir($root.'/.git')) {
            $problems[] = 'no_repository';
        }

        // Without a shared .env the release would generate a new APP_KEY, making every
        // encrypted column unreadable.
        if (! is_file($root.'/backend/.env')) {
            $problems[] = 'no_env';
        }

        if ($this->layout->usesSqlite() && $this->databaseFile() === null) {
            $problems[] = 'sqlite_not_found';
        }

        if (! is_writable($root)) {
            $problems[] = 'root_not_writable';
        }

        return $problems;
    }

    /**
     * The panel's SQLite file, as configured, or null when there is not one.
     *
     * Read from the connection, not assumed at the install-time path: it may have been moved.
     */
    public function databaseFile(): ?string
    {
        if (! $this->layout->usesSqlite()) {
            return null;
        }

        $connection = config('database.default');
        $path = config("database.connections.{$connection}.database");

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * The ordered steps, each with the shell it would run.
     *
     * Every path is server-side; nothing here is reachable from a request.
     *
     * @return array<int, array{step: string, commands: array<int, string>}>
     */
    public function plan(string $stamp): array
    {
        $root = $this->layout->root();
        $shared = $this->layout->sharedPath();
        $release = $this->layout->newReleasePath($stamp);

        $steps = [];

        $steps[] = ['step' => 'backup_database', 'commands' => [
            // Refuse unsupported queue policy BEFORE moving any live path;
            // snapshot only after the accepted job has drained, not before.
            sprintf('%s %s panel:queue-worker', $this->php(), escapeshellarg($root.'/backend/artisan')),
            sprintf('systemctl stop %s', escapeshellarg($this->service('queue'))),
            sprintf('%s %s panel:backup-database', $this->php(), escapeshellarg($root.'/backend/artisan')),
        ]];

        $steps[] = ['step' => 'create_layout', 'commands' => [
            sprintf('mkdir -p %s %s %s', escapeshellarg($this->layout->releasesPath()),
                escapeshellarg($shared), escapeshellarg($shared.'/database')),
        ]];

        // Everything outside the new layout becomes the first release. `find`, not a glob, so
        // dotfiles (.git, .env) come too.
        $steps[] = ['step' => 'move_checkout', 'commands' => [
            sprintf('mkdir -p %s', escapeshellarg($release)),
            sprintf(
                'find %s -mindepth 1 -maxdepth 1 %s -exec mv -t %s {} +',
                escapeshellarg($root),
                implode(' ', array_map(
                    fn (string $dir): string => '! -name '.escapeshellarg($dir),
                    self::LAYOUT_DIRS,
                )),
                escapeshellarg($release),
            ),
        ]];

        $steps[] = ['step' => 'extract_shared', 'commands' => $this->extractShared($release, $shared)];

        // No shell commands: the command rewrites .env itself (it holds the APP_KEY) and
        // reports the lines on a dry run; the step is listed so the plan matches what runs.
        $steps[] = ['step' => 'rewrite_env', 'commands' => []];

        // The repository leaves the release (same shape as a `git archive` release); the
        // update fetches from shared/repo.
        $steps[] = ['step' => 'move_repository', 'commands' => [
            sprintf('mkdir -p %s', escapeshellarg($shared.'/repo')),
            sprintf('mv %s %s', escapeshellarg($release.'/.git'), escapeshellarg($shared.'/repo/.git')),
        ]];

        $steps[] = ['step' => 'link_shared', 'commands' => $this->releases->linkShared($release)];

        $steps[] = ['step' => 'activate', 'commands' => [$this->releases->activate($release)]];

        // Compatibility links: every unit, vhost, pool and cron line names these paths and
        // now resolves into the live release unedited.
        $steps[] = ['step' => 'link_legacy_paths', 'commands' => [
            sprintf('ln -sfn %s %s', escapeshellarg($this->layout->currentLink().'/backend'), escapeshellarg($root.'/backend')),
            sprintf('ln -sfn %s %s', escapeshellarg($this->layout->currentLink().'/frontend'), escapeshellarg($root.'/frontend')),
        ]];

        $steps[] = ['step' => 'restart_services', 'commands' => [
            sprintf('%s %s optimize:clear', $this->php(), escapeshellarg($root.'/backend/artisan')),
            sprintf('systemctl reload %s', escapeshellarg($this->service('php_fpm'))),
            sprintf('systemctl restart %s', escapeshellarg($this->service('frontend'))),
            sprintf('systemctl restart %s', escapeshellarg($this->service('queue'))),
        ]];

        return $steps;
    }

    /**
     * Shared state out of the release and into `shared/`.
     *
     * Driven by the layout's own map, so a path added there is migrated as well as linked.
     *
     * @return array<int, string>
     */
    private function extractShared(string $release, string $shared): array
    {
        $commands = [];

        foreach ($this->layout->sharedMap() as $inRelease => $inShared) {
            $from = $release.'/'.$inRelease;
            $to = $shared.'/'.$inShared;

            // `-n`: never overwrite, so a second run cannot replace the live .env or database.
            $commands[] = sprintf(
                'test -e %s && mv -n %s %s || true',
                escapeshellarg($from),
                escapeshellarg($from),
                escapeshellarg($to),
            );
        }

        return $commands;
    }

    /**
     * The `.env` settings the new layout requires, as key => value.
     *
     * `PANEL_ROOT`: a migrated panel's own path resolves through `current` to a release.
     *
     * `DB_DATABASE`: install.sh wrote a path into the old checkout, which becomes a symlink.
     * Point at `shared/`, not through `current`: SQLite creates a missing file instead of
     * failing, so a broken symlink would silently produce an empty panel.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $settings = ['PANEL_ROOT' => $this->layout->root()];

        if ($this->layout->usesSqlite()) {
            $settings['DB_DATABASE'] = $this->layout->sharedPath().'/database/database.sqlite';
        }

        return $settings;
    }

    private function php(): string
    {
        return $this->phpBinary->path();
    }

    private function service(string $key): string
    {
        return (string) config('panel_update.services.'.$key);
    }
}
