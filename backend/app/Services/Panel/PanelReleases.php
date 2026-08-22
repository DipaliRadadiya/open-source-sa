<?php

namespace App\Services\Panel;

/**
 * The shell the update runs to build, activate and retire a release.
 *
 * Rendered rather than executed. The update is a detached bash script — it has
 * to outlive php-fpm being reloaded and the panel's own code being replaced
 * mid-run, so the steps that cross that moment cannot be PHP: the interpreter
 * running them is one of the things being swapped. Keeping them as text also
 * makes them testable without a filesystem, which for `rm -rf` and a symlink
 * the whole panel is served through is the difference between a tested design
 * and a hopeful one.
 *
 * Every path is escaped at the point of rendering. None of these values come
 * from a request — the tag is validated upstream and the rest is config — but
 * a command built by string concatenation is one careless caller away from
 * being a shell injection, and the escaping costs nothing.
 */
class PanelReleases
{
    /**
     * Releases kept, including the live one.
     *
     * Two is one rollback. Each release carries `vendor/`, `node_modules/` and
     * `.next/` — 600 MB to 1 GB — and this panel is built for small VPSes, so
     * a third costs real disk to buy a second undo nobody has asked for.
     */
    public const KEEP = 2;

    public function __construct(private PanelLayout $layout) {}

    /**
     * Extract a tag into a new release directory.
     *
     * `git archive` rather than a worktree or a clone: the release ends up with
     * no VCS metadata at all, so it cannot be checked out, reset, or left with
     * a lock file by something else. It also decouples releases from one object
     * store — a corrupted `.git` cannot take every release with it.
     *
     * Extracted into the final path directly, not staged elsewhere and moved:
     * the directory is worthless until the steps after it succeed, and the
     * caller removes it on any failure.
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
     * Runs before anything executes inside the release. A release that ran
     * `composer install` or a build before this would create its own `storage/`
     * and, worse, could generate an `APP_KEY` into a `.env` of its own —
     * making every encrypted column in the database unreadable.
     *
     * The parent directory of each link is created, and anything the archive
     * shipped at that path is removed first: `ln -s` onto an existing directory
     * silently creates the link *inside* it, which would leave the release
     * reading a `storage/storage` that nothing writes to.
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
     * **`ln -sfn` alone is not atomic.** It unlinks the existing symlink and
     * creates a new one; between those two calls the panel has no `current` at
     * all, and every service pointed through it is serving nothing. The window
     * is short and this is exactly the kind of race that shows up once a
     * quarter on a busy box and is never reproducible.
     *
     * So the link is built beside the real one and moved onto it with `mv -T`,
     * which is a single `rename(2)`: either the old target or the new one, with
     * nothing in between and no window to lose.
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
     * The same atomic swap, deliberately: rollback is the path that runs when
     * something has already gone wrong, and it must not have a failure mode the
     * forward path does not. The previous release still has its own `vendor/`
     * and built frontend, so it is runnable the instant it is pointed at —
     * which is the whole reason releases are kept rather than rebuilt.
     */
    public function rollback(string $previousReleasePath): string
    {
        return $this->activate($previousReleasePath);
    }

    /**
     * Remove releases past the retention limit, newest kept.
     *
     * Sorted by name, which is the timestamp — the reason release directories
     * are named the way they are.
     *
     * **The live release is excluded by asking, not by assuming.** An earlier
     * version kept the newest N on the reasoning that the live one is always
     * among them. That is true right up until a rollback, which deliberately
     * points `current` at an *older* release — and then the next prune deletes
     * the release being served and leaves a dangling symlink, which is every
     * service on the box serving nothing. Found by running this against a real
     * directory; no amount of asserting on the rendered string would have
     * shown it.
     *
     * So `KEEP` is a floor, not an exact count: while a rolled-back release is
     * live, three directories survive rather than two. That is the correct
     * trade — one extra release costs disk, deleting the running one costs the
     * server.
     *
     * Runs last and its failure is not the update's failure: disk left
     * uncollected is untidy, an update reported as failed because of it is
     * misleading.
     */
    public function prune(int $keep = self::KEEP): string
    {
        return sprintf(
            // `readlink -f` first, so the exclusion is the resolved directory
            // rather than the link itself.
            //
            // `find` rather than `ls -1d */`: it emits no trailing slash, so the
            // comparison needs no `sed` to strip one — and a `sed` expression
            // inside a rendered shell string is a quoting bug waiting to happen.
            // `grep -vxF` is a whole-line literal match: a release path is a
            // path, not a pattern.
            'CURRENT=$(readlink -f %s 2>/dev/null || true); '
            .'find %s -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +%d '
            .'| grep -vxF "$CURRENT" | xargs -r rm -rf',
            escapeshellarg($this->layout->currentLink()),
            escapeshellarg(rtrim($this->layout->releasesPath(), '/')),
            $keep + 1,
        );
    }
}
