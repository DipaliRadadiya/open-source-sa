<?php

namespace App\Services\Panel;

use Illuminate\Container\Container;

/**
 * Where the panel's own files live, and which of two shapes they are in.
 *
 * **Legacy** — one git checkout, updated in place:
 *
 *     /var/www/panel/            ← backend/, frontend/, .git, .env inside it
 *
 * **Released** — what the update rebuild moves to:
 *
 *     /var/www/panel/
 *       shared/                  ← .env, storage, sqlite: outlives every release
 *       releases/20260822-093000/
 *       current -> releases/20260822-093000
 *
 * Both are supported at once: every running server is legacy, and migrating is the riskiest
 * part of the update. Nothing else may assume a layout; it asks this class.
 *
 * Paths only. Disk access belongs to the callers (through ServerOps), which keeps the layout
 * rules testable without a filesystem.
 */
class PanelLayout
{
    /**
     * @param  string|null  $basePath  the Laravel app directory; defaults to this installation's
     *                                 own. Injectable so tests can exercise both shapes.
     */
    public function __construct(private ?string $basePath = null) {}

    /**
     * The directory holding `releases/`, `shared/` and `current`.
     *
     * Taken from config when set (install.sh and the migration record it); otherwise inferred
     * from where this code runs, for installs that predate the setting and for tests.
     */
    public function root(): string
    {
        // Asked of the container, not config(): this runs where no application is booted,
        // including while the layout is being built, and config() throws when nothing is bound.
        $container = Container::getInstance();

        $configured = $container->bound('config')
            ? $container->make('config')->get('panel_update.root')
            : null;

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $repository = dirname($this->basePath ?? base_path());

        // `<root>/releases/<timestamp>/backend` — the shape that reaches this in production.
        // NOT detected via `current`: PHP resolves symlinks in `__DIR__`, so `current` is already
        // gone from base_path(). Matching on it would make a migrated server run the legacy
        // update (a `git checkout` in a directory with no .git).
        if (basename(dirname($repository)) === 'releases') {
            return dirname(dirname($repository));
        }

        // The literal `current` spelling, for a path handed in rather than resolved off disk.
        if (basename($repository) === self::CURRENT) {
            return dirname($repository);
        }

        // Legacy: one checkout, `<root>/backend`.
        return $repository;
    }

    /** The symlink every service points at. */
    public const CURRENT = 'current';

    public function currentLink(): string
    {
        return $this->root().'/'.self::CURRENT;
    }

    public function releasesPath(): string
    {
        return $this->root().'/releases';
    }

    public function sharedPath(): string
    {
        return $this->root().'/shared';
    }

    /**
     * Whether this install has been migrated.
     *
     * Checks the releases directory, not `current`: a half-finished migration can leave the
     * directory without the symlink, and calling that legacy would build a second layout.
     */
    public function isReleased(): bool
    {
        return is_dir($this->releasesPath());
    }

    /**
     * The release directory a new version would be built into.
     *
     * Sortable, second-resolution, and never reused: the timestamp is the release's identity.
     */
    public function newReleasePath(string $at): string
    {
        return $this->releasesPath().'/'.$at;
    }

    /**
     * Files that live in `shared/` and are symlinked into each release.
     *
     * The map is the contract: a mistake here destroys data. `.env` carries `APP_KEY`, and a
     * release with its own key would make every encrypted column unreadable.
     *
     * @return array<string, string> path inside a release => path inside shared/
     */
    public function sharedMap(): array
    {
        $map = [
            'backend/.env' => '.env',
            'backend/storage' => 'storage',
            'frontend/.env.production' => 'frontend.env',
        ];

        // The panel's own database, when it is a file. `git archive` never carries the SQLite
        // file into a release (it is gitignored), and SQLite silently creates a missing database:
        // without this the update would "succeed" onto an empty panel.
        //
        // SQLite only: on MySQL there is no file, and `linkShared()` removes the target before
        // linking, leaving a dangling symlink.
        if ($this->usesSqlite()) {
            $map['backend/database/database.sqlite'] = 'database/database.sqlite';
        }

        return $map;
    }

    /**
     * Whether this panel keeps its own data in a SQLite file.
     *
     * Container-guarded like {@see root()}: there may be no application yet.
     */
    public function usesSqlite(): bool
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return false;
        }

        $config = $container->make('config');
        $connection = $config->get('database.default');

        return $config->get("database.connections.{$connection}.driver") === 'sqlite';
    }
}
