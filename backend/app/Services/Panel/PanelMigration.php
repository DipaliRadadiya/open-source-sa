<?php

namespace App\Services\Panel;

/**
 * Moving a live, working panel from one checkout to the release layout.
 *
 * The riskiest thing in this design, and the design doc says so: the update
 * runs on a server someone has asked to be updated, but this runs on a server
 * that is currently fine. So the shape of it is chosen for what happens when
 * it goes wrong, not for tidiness.
 *
 * **Everything is a move, never a copy.** The existing checkout becomes the
 * first release, carrying its own `vendor/` and `.next/` with it — so there is
 * no rebuild, no second copy of a 600 MB tree on a small VPS, and no window
 * where the panel is being reinstalled rather than rearranged.
 *
 * **The old paths keep working.** `<root>/backend` and `<root>/frontend`
 * survive as symlinks into `current/`. That is not tidiness either: the
 * checkout path is baked into panel-frontend.service, panel-queue.service, the
 * php-fpm pool, the schedule cron line and the web-server vhost — and the
 * panel supports nginx, Apache and OpenLiteSpeed, three formats. Rewriting all
 * of that on a working server is the single most likely way to take one down.
 * Two symlinks make every one of those files correct without being touched.
 *
 * Planning only — this class writes nothing. {@see \App\Console\Commands\
 * MigratePanelLayout} executes, and dry-runs by default, so what an operator
 * reads before committing is the same list that runs.
 */
class PanelMigration
{
    /** Directories that are the new layout, not content to be moved into it. */
    private const LAYOUT_DIRS = ['releases', 'shared', 'current'];

    public function __construct(
        private PanelLayout $layout,
        private PanelReleases $releases,
    ) {}

    /**
     * Reasons this server must not be migrated, as translation keys.
     *
     * Checked before anything moves, and the command refuses on any of them.
     * A migration that discovers a problem halfway leaves a panel in neither
     * shape.
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

        // Without this the release cannot be given a shared .env, and the
        // first thing to run in it generates an APP_KEY — which makes every
        // encrypted column unreadable. Storage secrets, git tokens, database
        // passwords. Refuse rather than discover it after the swap.
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
     * Read from the connection rather than assumed to be at the install-time
     * path: an operator may have moved it, and migrating the wrong file would
     * leave the panel pointed at a database that is not the one it was using.
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
     * Every path is server-side: the root comes from the layout, the stamp
     * from the caller. Nothing here is reachable from a request.
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
            sprintf('%s %s panel:backup-database', $this->php(), escapeshellarg($root.'/backend/artisan')),
        ]];

        $steps[] = ['step' => 'create_layout', 'commands' => [
            sprintf('mkdir -p %s %s %s', escapeshellarg($this->layout->releasesPath()),
                escapeshellarg($shared), escapeshellarg($shared.'/database')),
        ]];

        // Everything that is not the new layout becomes the first release —
        // including vendor/ and .next/, which is what makes this cheap. `find`
        // rather than a glob so dotfiles come too: .git and .env are the two
        // that matter most and a plain `mv *` silently leaves both behind.
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

        // The repository leaves the release, so a release built by `git
        // archive` and this one have the same shape: code, no VCS metadata.
        // The update reads and fetches from shared/repo.
        // No shell: rewriting .env is line-editing, and sed against a file
        // holding an APP_KEY is not worth the cleverness. The command owns it
        // and reports the exact lines during a dry run — but the step is named
        // here so the plan an operator reads is the plan that runs.
        $steps[] = ['step' => 'rewrite_env', 'commands' => []];

        $steps[] = ['step' => 'move_repository', 'commands' => [
            sprintf('mkdir -p %s', escapeshellarg($shared.'/repo')),
            sprintf('mv %s %s', escapeshellarg($release.'/.git'), escapeshellarg($shared.'/repo/.git')),
        ]];

        $steps[] = ['step' => 'link_shared', 'commands' => $this->releases->linkShared($release)];

        $steps[] = ['step' => 'activate', 'commands' => [$this->releases->activate($release)]];

        // The compatibility links. Every unit, vhost, pool and cron line on
        // the box names these paths, and after this they all resolve into the
        // live release without one of them being edited.
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
     * Driven by the layout's own map rather than a second list here, so a path
     * added to the contract is migrated as well as linked. The two disagreeing
     * is how a release ends up with its own .env.
     *
     * @return array<int, string>
     */
    private function extractShared(string $release, string $shared): array
    {
        $commands = [];

        foreach ($this->layout->sharedMap() as $inRelease => $inShared) {
            $from = $release.'/'.$inRelease;
            $to = $shared.'/'.$inShared;

            // `-n` — never overwrite. A second run must not replace the live
            // .env or database with whatever the release happens to hold, and
            // this command has to be safe to run twice.
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
     * `PANEL_ROOT` because a migrated panel cannot infer where its layout
     * begins — its own path resolves through `current` to a release.
     *
     * `DB_DATABASE` because install.sh wrote an absolute path into the old
     * checkout, and that directory is about to become a symlink. Pointed at
     * `shared/`, which nothing moves, rather than back through `current` —
     * SQLite creates a missing file instead of failing, so a path that depends
     * on two symlinks being intact is a path that can silently produce an
     * empty panel.
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
        return '/usr/bin/php'.config('panel_update.php_version');
    }

    private function service(string $key): string
    {
        return (string) config('panel_update.services.'.$key);
    }
}
