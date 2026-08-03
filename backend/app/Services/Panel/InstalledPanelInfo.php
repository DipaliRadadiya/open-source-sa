<?php

namespace App\Services\Panel;

/**
 * What the running panel thinks it is, derived without shelling out.
 *
 * The "installed commit/version" the admin screen needs to display must come
 * from something already on disk: `config('app.version')` is the version the
 * operator pinned at deploy time, and `.git/HEAD` + its ref file resolve to
 * the commit the working tree is checked out at. Nothing here calls `git`,
 * `composer`, or `Process::run()` — a shell call would be the wrong answer
 * for a screen an admin is looking at, because the privileged helper that
 * runs the actual switch will need this same fact, and reading from a process
 * pipe on every request would be both slow and racy with the file on disk.
 *
 * Three honest answers are returned, and which one is shown depends on what
 * is available:
 *
 *  - `config`:  the version came from `APP_VERSION` and no git metadata was
 *               reachable (a packaged release without a `.git/` directory).
 *  - `git`:     the version came from `APP_VERSION` AND a `.git/HEAD` file
 *               resolved to a commit hash. Both are present, both are real.
 *  - `unknown`: neither. The screen still renders; the fields are null.
 */
class InstalledPanelInfo
{
    /**
     * @return array{
     *     version: ?string,
     *     commit_hash: ?string,
     *     commit_short: ?string,
     *     source: 'config'|'git'|'unknown'
     * }
     */
    public function installed(): array
    {
        $version = $this->version();
        $commit = $this->commit();

        if ($version === null && $commit === null) {
            $source = 'unknown';
        } elseif ($commit === null) {
            $source = 'config';
        } else {
            $source = 'git';
        }

        return [
            'version' => $version,
            'commit_hash' => $commit,
            'commit_short' => $commit === null ? null : substr($commit, 0, 7),
            'source' => $source,
        ];
    }

    private function version(): ?string
    {
        $value = config('app.version');

        if ($value === null || $value === '') {
            return null;
        }

        // The default is the literal string '1.0.0'; it would be a lie to
        // show that as "the installed version" when the operator never pinned
        // one — the screen would tell the admin "this build is 1.0.0" and
        // have nothing to back it up.
        if ($value === '1.0.0' && env('APP_VERSION') === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Resolve the current commit by reading the `.git/HEAD` ref pointer and
     * the file it points at. Returns null when either file is missing or
     * unreadable — a packaged release without a `.git/` directory, or a
     * worktree with an unresolved HEAD, both fail closed.
     */
    private function commit(): ?string
    {
        $headFile = base_path('.git/HEAD');

        if (! is_file($headFile) || ! is_readable($headFile)) {
            return null;
        }

        $head = trim((string) @file_get_contents($headFile));

        // Detached HEAD: the file already contains the commit hash, not a ref
        // pointer. This is what every fresh clone reports before `git
        // checkout` moves it, and what CI checkouts always report.
        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) {
            return $head;
        }

        // Symbolic ref: `ref: refs/heads/feat/admin-panel-updates`. Read the
        // file it points at — the same way git itself does — to get the hash.
        if (preg_match('#^ref:\s*(.+)$#', $head, $matches) === 1) {
            $refPath = base_path('.git/'.trim($matches[1]));

            if (! is_file($refPath) || ! is_readable($refPath)) {
                return null;
            }

            $hash = trim((string) @file_get_contents($refPath));

            return preg_match('/^[0-9a-f]{40}$/i', $hash) === 1 ? $hash : null;
        }

        return null;
    }
}