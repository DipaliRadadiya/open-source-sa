<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Process;

/**
 * What the running panel actually is, read from disk.
 *
 * The version is the tag HEAD is sitting on, falling back to the `VERSION`
 * file at the repository root.
 *
 * It used to be the file alone, on the reasoning that install.sh clones with
 * `--depth 1` and fetches no tags. True for a *fresh* install — which is why
 * the file is still the fallback — but false for the case that matters: an
 * update runs `git fetch origin refs/tags/vX` and then checks that tag out, so
 * afterwards the tag is present and HEAD is exactly on it. Verified on a real
 * shallow clone: `git describe --tags --exact-match HEAD` answers `v1.0.3`.
 *
 * That ordering exists because the file is maintained by hand and keeps being
 * forgotten. v1.0.2 and v1.0.3 were both tagged without bumping it, so both
 * ship `VERSION=1.0.1` — and the update's health check asserts the version it
 * installed is the version answering, which can never be true for those tags.
 * Every update to them builds for twenty minutes and then rolls back. Asking
 * git what was checked out cannot disagree with what was checked out.
 *
 * `APP_VERSION` in the environment still wins when set, so an operator running
 * from a package or a pinned build can override both.
 *
 * The commit is read from `.git/HEAD` and the ref it points at, the same way
 * git resolves it, rather than by shelling out — this is read on a screen the
 * admin is looking at, and a process pipe per request would be both slower and
 * racy against the file it is reading.
 */
class InstalledPanelInfo
{
    /**
     * @return array{
     *     version: ?string,
     *     commit_hash: ?string,
     *     commit_short: ?string,
     *     branch: ?string,
     *     source: 'tag'|'file'|'env'|'unknown',
     *     is_git_checkout: bool,
     *     has_local_changes: ?bool,
     * }
     */
    public function installed(): array
    {
        [$version, $source] = $this->version();
        $commit = $this->commit();

        return [
            'version' => $version,
            'commit_hash' => $commit,
            'commit_short' => $commit === null ? null : substr($commit, 0, 7),
            'branch' => $this->branch(),
            'source' => $source,
            // Whether an in-place update is even possible on this box. A
            // packaged install without .git cannot be moved by git checkout.
            'is_git_checkout' => is_dir($this->repositoryPath().'/.git'),
            'has_local_changes' => $this->hasLocalChanges(),
        ];
    }

    /**
     * The tag HEAD points at exactly, without the leading `v`.
     *
     * Shelling out, like {@see localChanges()} already does. Reconstructing
     * this from .git by hand would mean resolving annotated tag objects to the
     * commits they wrap, which is git's job and not worth reimplementing for a
     * few milliseconds.
     *
     * Any failure is null, not an exception: a packaged install with no .git,
     * a shallow clone with no tags fetched yet, or a git that refuses the
     * directory. All of those mean "no tag here", and the file answers.
     */
    private function exactTag(): ?string
    {
        $path = $this->repositoryPath();

        if (! is_dir($path.'/.git')) {
            return null;
        }

        $result = Process::path($path)
            ->timeout(10)
            ->run(['git', '-c', 'safe.directory='.$path, 'describe', '--tags', '--exact-match', 'HEAD']);

        if (! $result->successful()) {
            return null;
        }

        $tag = ltrim(trim($result->output()), 'vV');

        // Only a version-shaped tag. A `nightly` or `staging` tag is a real
        // thing to have on a commit, and reporting it as the installed version
        // would put a word where the update compares numbers.
        return preg_match('/^\d+(\.\d+){0,3}$/', $tag) === 1 ? $tag : null;
    }

    /**
     * Repository root — one level above the Laravel app, because the panel is
     * a mono-repo (backend/ + frontend/ + install.sh + VERSION).
     */
    public function repositoryPath(): string
    {
        return dirname(base_path());
    }

    /**
     * Uncommitted work in the checkout. An in-place `git checkout --force`
     * would destroy it without asking, so preflight has to know. Null when
     * this is not a git checkout at all, or git could not be run — "unknown"
     * and "clean" are very different answers and must not be conflated.
     */
    public function hasLocalChanges(): ?bool
    {
        $changes = $this->localChanges();

        return $changes === null ? null : $changes !== [];
    }

    /**
     * The paths git reports as changed, or null when that cannot be determined.
     *
     * The list, not just the yes/no, because this is what blocks an update and
     * "not ready, and I won't say why" is the worst thing a preflight can tell
     * you. On a real server this check refused every update while the detail
     * shown in the panel was empty — the only way to find out what was dirty
     * was to SSH in and run `git status` by hand.
     *
     * `safe.directory` travels with the call for the same reason it does in
     * the update script: an operator who has been root in this tree leaves
     * ownership git refuses to work with, and answering "unknown" there fails
     * the check closed and blocks updates for a reason nobody can see either.
     *
     * @return array<int, string>|null
     */
    public function localChanges(): ?array
    {
        $path = $this->repositoryPath();

        if (! is_dir($path.'/.git')) {
            return null;
        }

        $result = Process::path($path)
            ->timeout(10)
            ->run(['git', '-c', 'safe.directory='.$path, 'status', '--porcelain']);

        if (! $result->successful()) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", trim($result->output()))),
        ));
    }

    /**
     * @return array{0: ?string, 1: 'file'|'env'|'unknown'}
     */
    private function version(): array
    {
        // An explicitly pinned APP_VERSION wins over the tracked file.
        $env = env('APP_VERSION');

        if (is_string($env) && $env !== '') {
            return [$env, 'env'];
        }

        // The tag HEAD is on, when it is on one. After an update this is the
        // tag that was just checked out, which is the most direct possible
        // answer to "what is installed" — and unlike the file, it cannot have
        // been forgotten.
        //
        // `--exact-match` rather than a nearest-tag describe: sitting five
        // commits past v1.0.3 is not v1.0.3, and reporting it as such would
        // make the health check pass against code that is not the release.
        // A branch checkout (every fresh install) matches nothing and falls
        // through to the file.
        $tag = $this->exactTag();

        if ($tag !== null) {
            return [$tag, 'tag'];
        }

        $file = $this->repositoryPath().'/VERSION';

        if (is_file($file) && is_readable($file)) {
            $value = trim((string) @file_get_contents($file));

            if ($value !== '') {
                return [$value, 'file'];
            }
        }

        // Deliberately not '1.0.0'. config/app.php carries that as a default,
        // and showing it as "the installed version" would be a claim with
        // nothing behind it on a screen whose whole job is to be accurate.
        return [null, 'unknown'];
    }

    private function commit(): ?string
    {
        $head = $this->readGitFile('HEAD');

        if ($head === null) {
            return null;
        }

        // Detached HEAD — the file holds the hash itself. This is what a
        // `git checkout <tag>` leaves behind, which is exactly what an
        // in-place update does, so it is the common case here.
        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) {
            return $head;
        }

        if (preg_match('#^ref:\s*(.+)$#', $head, $matches) !== 1) {
            return null;
        }

        $hash = $this->readGitFile(trim($matches[1]));

        return $hash !== null && preg_match('/^[0-9a-f]{40}$/i', $hash) === 1 ? $hash : null;
    }

    /**
     * Null on a detached HEAD (an updated panel) — there is no branch then,
     * and inventing one would be a lie.
     */
    private function branch(): ?string
    {
        $head = $this->readGitFile('HEAD');

        if ($head === null || preg_match('#^ref:\s*refs/heads/(.+)$#', $head, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function readGitFile(string $relative): ?string
    {
        $path = $this->repositoryPath().'/.git/'.ltrim($relative, '/');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
