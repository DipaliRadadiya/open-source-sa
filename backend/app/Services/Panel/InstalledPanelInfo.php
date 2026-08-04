<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Process;

/**
 * What the running panel actually is, read from disk.
 *
 * The version comes from the `VERSION` file at the repository root — not from
 * a git tag. install.sh clones with `--depth 1`, which fetches no tags at all,
 * so tag-derived versioning reports nothing on a real installation. A tracked
 * file ships with the shallow clone and is therefore always present.
 *
 * `APP_VERSION` in the environment still wins when set, so an operator running
 * from a package or a pinned build can override what the file says.
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
     *     source: 'file'|'env'|'unknown',
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
        if (! is_dir($this->repositoryPath().'/.git')) {
            return null;
        }

        $result = Process::path($this->repositoryPath())
            ->timeout(10)
            ->run(['git', 'status', '--porcelain']);

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output()) !== '';
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
