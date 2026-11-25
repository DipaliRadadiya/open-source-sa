<?php

namespace App\Services\Panel;

/**
 * The shell the update runs to build, activate and retire a release.
 *
 * Rendered rather than executed: the update is a detached bash script that outlives php-fpm
 * reloads and the panel's code being replaced mid-run, so these steps cannot be PHP. As text they
 * are also testable without a filesystem.
 *
 * Every path is escaped at render time, even though none comes from a request.
 */
class PanelReleases
{
    /**
     * Releases kept, including the live one.
     *
     * Two is one rollback. A release carries `vendor/`, `node_modules/` and `.next/` (600 MB to
     * 1 GB), and the panel targets small VPSes.
     */
    public const KEEP = 2;

    public function __construct(private PanelLayout $layout) {}

    /**
     * Extract a tag into a new release directory.
     *
     * `git archive` rather than a worktree or clone: no VCS metadata in the release, and a
     * corrupted `.git` cannot take every release with it.
     *
     * Extracted straight into the final path; the caller removes it on any failure.
     */
    public function create(string $repository, string $tag, string $releasePath): string
    {
        return sprintf(
            'mkdir -p %s && git -c %s -C %s archive --format=tar %s | tar -x -C %s',
            escapeshellarg($releasePath),
            escapeshellarg('safe.directory='.$repository),
            escapeshellarg($repository),
            escapeshellarg($tag),
            escapeshellarg($releasePath),
        );
    }

    /**
     * Point a release's shared paths at `shared/`.
     *
     * Runs before anything executes inside the release: otherwise composer or a build could create
     * its own `storage/` and generate a new `APP_KEY`, making every encrypted column unreadable.
     *
     * Anything the archive shipped at the link path is removed first: `ln -s` onto an existing
     * directory creates the link *inside* it.
     *
     * @return array<int, string>
     */
    public function linkShared(string $releasePath): array
    {
        $commands = [];

        foreach ($this->layout->sharedMap() as $inRelease => $inShared) {
            $target = $releasePath.'/'.$inRelease;
            $source = $this->layout->sharedPath().'/'.$inShared;

            $commands[] = sprintf(
                'mkdir -p %s && rm -rf %s && ln -s %s %s',
                escapeshellarg(dirname($target)),
                escapeshellarg($target),
                escapeshellarg($source),
                escapeshellarg($target),
            );
        }

        return $commands;
    }

    /**
     * Make a release live.
     *
     * `ln -sfn` alone is not atomic: between its unlink and create there is no `current`. So the
     * link is built beside the real one and moved onto it with `mv -T`, a single `rename(2)`.
     */
    public function activate(string $releasePath): string
    {
        $pending = $this->layout->root().'/.current.pending';

        return sprintf(
            'ln -sfn %s %s && mv -T %s %s',
            escapeshellarg($releasePath),
            escapeshellarg($pending),
            escapeshellarg($pending),
            escapeshellarg($this->layout->currentLink()),
        );
    }

    /**
     * Return to the release that was live before.
     *
     * The same atomic swap: rollback must not have a failure mode the forward path lacks. The
     * previous release is runnable the moment it is pointed at.
     */
    public function rollback(string $previousReleasePath): string
    {
        return $this->activate($previousReleasePath);
    }

    /**
     * Remove releases past the retention limit, newest kept.
     *
     * Sorted by name, which is the timestamp.
     *
     * The live release is always excluded: after a rollback `current` points at an *older*
     * release, and keeping only the newest N would delete the one being served. So `KEEP` is a
     * floor: while a rolled-back release is live, three directories survive.
     *
     * Runs last; its failure is not the update's failure.
     */
    public function prune(int $keep = self::KEEP): string
    {
        return sprintf(
            // `readlink -f` first, so the exclusion is the resolved directory, not the link.
            // `find` emits no trailing slash, so no `sed` is needed. `grep -vxF` is a whole-line
            // literal match: a release path is not a pattern.
            'CURRENT=$(readlink -f %s 2>/dev/null || true); '
            .'find %s -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +%d '
            .'| grep -vxF "$CURRENT" | xargs -r rm -rf',
            escapeshellarg($this->layout->currentLink()),
            escapeshellarg(rtrim($this->layout->releasesPath(), '/')),
            $keep + 1,
        );
    }
}
