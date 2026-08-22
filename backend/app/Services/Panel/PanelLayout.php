<?php

namespace App\Services\Panel;

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
        $repository = dirname($this->basePath ?? base_path());

        // Running from inside a release: `<root>/current/backend` resolves its
        // repository to `<root>/current`, whose parent is the root. Compared on
        // the basename rather than by checking for `releases/` above, so this
        // answers correctly during a migration when only half the layout is
        // built.
        return basename($repository) === self::CURRENT
            ? dirname($repository)
            : $repository;
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
