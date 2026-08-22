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
 * Both are supported at once, deliberately and for a long time: every server
 * already running is legacy, and the migration between them is the riskiest
 * part of this work — it runs on machines that are currently fine. Nothing
 * else in the update may assume a layout; it asks this class.
 *
 * Paths only. Reading and writing the disk belongs to the callers, which run
 * through ServerOps and can be faked; keeping this pure is what makes the
 * layout rules testable without a filesystem.
 */
class PanelLayout
{
    /**
     * @param  string|null  $basePath  the Laravel app directory; defaults to
     *                                 this installation's own. Injectable so
     *                                 the layout rules can be exercised for
     *                                 both shapes without a filesystem.
     */
    public function __construct(private ?string $basePath = null) {}

    /**
     * The directory holding `releases/`, `shared/` and `current`.
     *
     * Derived from where this code is running rather than from config: the
     * panel is the one application that always knows where it is, and a config
     * value could disagree with reality after a migration.
     */
    public function root(): string
    {
        // Config first, because on a migrated install the panel cannot work
        // this out from where its code is. install.sh and the migration both
        // record it; everything below is inference for installs that predate
        // that, and for tests.
        // Asked of the container rather than through config(), which resolves
        // it and throws when nothing is bound. This class answers questions
        // about paths and is used from places that have no application booted
        // — including, by design, code that runs while the layout is being
        // built. Inference is the fallback there, exactly as for an install
        // that predates the setting.
        $container = Container::getInstance();

        $configured = $container->bound('config')
            ? $container->make('config')->get('panel_update.root')
            : null;

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $repository = dirname($this->basePath ?? base_path());

        // `<root>/releases/<timestamp>/backend` — the shape that actually
        // reaches this in production.
        //
        // NOT detected via `current`, even though that is the path the service
        // is started with. `base_path()` comes from `dirname(__DIR__)` in
        // bootstrap/app.php, and PHP resolves symlinks in `__DIR__` — so by the
        // time this runs, `<root>/current/backend` has already become
        // `<root>/releases/20260822-093000/backend` and the word `current`
        // is gone. An earlier version matched on it and therefore never fired:
        // root() answered `<root>/releases/<timestamp>`, isReleased() looked
        // for a releases/ directory inside a release, found none, and every
        // migrated server quietly ran the legacy update — a `git checkout` in
        // a directory built by `git archive`, which has no .git at all.
        if (basename(dirname($repository)) === 'releases') {
            return dirname(dirname($repository));
        }

        // The literal `current` spelling, for a path handed in rather than
        // resolved off disk. It cannot occur in production for the reason
        // above, but a caller that constructs the path itself is entitled to
        // a correct answer.
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
     * `is_dir` on the releases directory, not on `current`: a half-finished
     * migration can leave the directory without the symlink, and treating that
     * as legacy would make the next update build a second layout beside the
     * first.
     */
    public function isReleased(): bool
    {
        return is_dir($this->releasesPath());
    }

    /**
     * The release directory a new version would be built into.
     *
     * Sortable, second-resolution, and never reused: the timestamp is the
     * release's identity everywhere else, and two releases a second apart are
     * a retry, not a collision worth handling.
     */
    public function newReleasePath(string $at): string
    {
        return $this->releasesPath().'/'.$at;
    }

    /**
     * Files that live in `shared/` and are symlinked into each release.
     *
     * The map is the contract: getting it wrong is the one way this design
     * destroys data. `.env` carries `APP_KEY`, and a release that generated its
     * own would make every encrypted column — storage secrets, git tokens,
     * database passwords — unreadable.
     *
     * @return array<string, string> path inside a release => path inside shared/
     */
    public function sharedMap(): array
    {
        return [
            'backend/.env' => '.env',
            'backend/storage' => 'storage',
            'frontend/.env.production' => 'frontend.env',
        ];
    }
}
