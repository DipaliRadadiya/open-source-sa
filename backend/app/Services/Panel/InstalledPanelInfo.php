<?php

namespace App\Services\Panel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * What the running panel actually is, read from disk.
 *
 * The version is the exact tag at HEAD, or the newest version tag reachable
 * from HEAD when this is a development build ahead of a release. Only a clone
 * with no reachable tags falls back to the `VERSION` file.
 *
 * Git is asked before the `VERSION` file: a fresh install is a `--depth 1` clone with no
 * tags (hence the file fallback), but an update fetches and checks out its tag, so HEAD is
 * exactly on it. The file is maintained by hand and gets forgotten, and the update's health
 * check asserts the installed version is the one answering.
 *
 * `APP_VERSION` in the environment still wins when set.
 *
 * The commit is read from `.git/HEAD` and its ref, the way git resolves it, rather than by
 * shelling out: it is read on a screen the admin is looking at.
 */
class InstalledPanelInfo
{
    /**
     * @return array{
     *     version: ?string,
     *     commit_hash: ?string,
     *     commit_short: ?string,
     *     branch: ?string,
     *     source: 'tag'|'tag-ahead'|'file'|'env'|'unknown',
     *     committed_at: ?string,
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
            // When the running code was committed; on a fresh install (shallow clone, no tags)
            // the only fact relating it to a release: see AvailableRelease::isUpdate().
            'committed_at' => $this->committedAt(),
            // An install without .git cannot be updated in place.
            'is_git_checkout' => $this->isGitCheckout(),
            'has_local_changes' => $this->hasLocalChanges(),
        ];
    }

    /**
     * Whether the code checked out already contains release `$version`.
     *
     * The installed version comes from local tags, so a release tagged on the very commit
     * installed here would read as an update ("Update available", ending in
     * `target_not_newer`). Asked of git by commit instead: the release's commit is an
     * ancestor of HEAD or it is not (local tag first, then the remote's). Null when git
     * cannot tell; remembered per HEAD for an hour.
     */
    public function alreadyContains(string $version, ?string $head = null): ?bool
    {
        $path = $this->repositoryPath();

        if (! $this->isGitCheckout() || preg_match('/^\d+(\.\d+){0,3}$/', $version) !== 1) {
            return null;
        }

        $git = fn (array $args, int $timeout = 10) => $this->git($path, $args, $timeout);

        $head ??= trim($git(['rev-parse', 'HEAD'])->output());

        if (preg_match('/^[0-9a-f]{40}$/', $head) !== 1) {
            return null;
        }

        // Boxed: the cache treats a stored null as a miss, and "cannot tell" without egress
        // must not re-run a 15 s ls-remote per load.
        return Cache::remember("panel_update.contains.{$head}.{$version}", now()->addHour(), fn (): array => ['answer' => $this->askContains($git, $version, $head)])['answer'];
    }

    /**
     * @param  \Closure(array<int, string>, int=): ProcessResult  $git
     */
    private function askContains(\Closure $git, string $version, string $head): ?bool
    {
        $commit = trim($git(['rev-parse', '--verify', '--quiet', "refs/tags/v{$version}^{commit}"])->output());

        if ($commit === '') {
            $remote = $git(['ls-remote', '--tags', 'origin', "refs/tags/v{$version}", "refs/tags/v{$version}^{}"], 15)->output();
            // The peeled line (`^{}`) is the commit an annotated tag wraps.
            preg_match_all('/^([0-9a-f]{40})\s+refs\/tags\/v'.preg_quote($version, '/').'(\^\{\})?$/m', $remote, $m, PREG_SET_ORDER);
            $peeled = collect($m)->first(fn ($row) => ($row[2] ?? '') !== '') ?? ($m[0] ?? null);
            $commit = $peeled[1] ?? '';
        }

        if ($commit === '') {
            return null;
        }

        $exit = $git(['merge-base', '--is-ancestor', $commit, $head])->exitCode();

        // 0 contains it, 1 does not; anything else (commit not in this clone) is "cannot tell".
        return match ($exit) {
            0 => true,
            1 => false,
            default => null,
        };
    }

    /**
     * The tag HEAD points at exactly, without the leading `v`.
     *
     * Shells out like {@see localChanges()} (resolving annotated tag objects by hand is git's
     * job). Any failure is null ("no tag here") and the file answers.
     */
    private function exactTag(): ?string
    {
        $path = $this->repositoryPath();

        if (! $this->isGitCheckout()) {
            return null;
        }

        $result = $this->git($path, ['describe', '--tags', '--exact-match', 'HEAD'], 10);

        if (! $result->successful()) {
            return null;
        }

        $tag = ltrim(trim($result->output()), 'vV');

        // Only a version-shaped tag: a `nightly` tag must not be reported as a version.
        return preg_match('/^\d+(\.\d+){0,3}$/', $tag) === 1 ? $tag : null;
    }

    /**
     * HEAD's commit date (ISO 8601), or null when git cannot say.
     */
    private function committedAt(): ?string
    {
        $path = $this->repositoryPath();

        if (! $this->isGitCheckout()) {
            return null;
        }

        $result = $this->git($path, ['log', '-1', '--format=%cI', 'HEAD'], 10);

        $date = trim($result->output());

        return $result->successful() && $date !== '' ? $date : null;
    }

    /**
     * One git command in the panel's own checkout; a timeout is answered "could not tell"
     * rather than thrown, so a busy box does not 500 GET /api/health.
     *
     * @param  array<int, string>  $args
     */
    private function git(string $path, array $args, int $timeout = 10): ProcessResult
    {
        try {
            return Process::path($path)->timeout($timeout)->run(['git', '-c', 'safe.directory='.$path, ...$args]);
        } catch (ProcessTimedOutException) {
            return new FakeProcessResult(errorOutput: 'timed out', exitCode: 124);
        }
    }

    /**
     * Repository root: one level above the Laravel app (the panel is a mono-repo).
     */
    public function repositoryPath(): string
    {
        return dirname(base_path());
    }

    /**
     * Uncommitted work in the checkout (an in-place `git checkout --force` would destroy it).
     * Null when this is not a git checkout or git could not be run: "unknown" is not "clean".
     */
    public function hasLocalChanges(): ?bool
    {
        $changes = $this->localChanges();

        return $changes === null ? null : $changes !== [];
    }

    /**
     * The paths git reports as changed, or null when that cannot be determined.
     *
     * The list, not just yes/no: this is what blocks an update, and the panel must show why.
     *
     * `safe.directory` travels with the call, as in the update script: a tree once used as
     * root has ownership git refuses, and "unknown" would block updates for an invisible reason.
     *
     * @return array<int, string>|null
     */
    public function localChanges(): ?array
    {
        $path = $this->repositoryPath();

        if (! $this->isGitCheckout()) {
            return null;
        }

        $result = $this->git($path, ['status', '--porcelain'], 10);

        if (! $result->successful()) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", trim($result->output()))),
        ));
    }

    /**
     * The nearest reachable version tag, without claiming HEAD is exactly it.
     *
     * A panel deployed from main can be several commits ahead of the latest release; a stale
     * VERSION file would make the UI offer the latest tag as an "update" and check out older
     * code. Source `tag-ahead` keeps the distinction visible to API consumers.
     */
    private function nearestTag(): ?string
    {
        $path = $this->repositoryPath();

        if (! $this->isGitCheckout()) {
            return null;
        }

        $result = $this->git($path, ['describe', '--tags', '--abbrev=0', 'HEAD'], 10);

        if (! $result->successful()) {
            return null;
        }

        $tag = ltrim(trim($result->output()), 'vV');

        return preg_match('/^\d+(\.\d+){0,3}$/', $tag) === 1 ? $tag : null;
    }

    /**
     * Just the version, for a reader that needs nothing else: the same answer
     * {@see installed()} gives, without its commit, branch and change checks.
     */
    public function versionNumber(): ?string
    {
        return $this->version()[0];
    }

    /**
     * @return array{0: ?string, 1: 'tag'|'tag-ahead'|'file'|'env'|'unknown'}
     */
    private function version(): array
    {
        // An explicitly pinned APP_VERSION wins over the tracked file.
        $env = env('APP_VERSION');

        if (is_string($env) && $env !== '') {
            return [$env, 'env'];
        }

        // The tag HEAD is on, when it is on one (after an update, the tag just checked out).
        // `--exact-match`: five commits past v1.0.3 is not v1.0.3, or the health check would
        // pass against code that is not the release. A branch checkout falls through to the file.
        $tag = $this->exactTag();

        if ($tag !== null) {
            return [$tag, 'tag'];
        }

        $nearest = $this->nearestTag();

        if ($nearest !== null) {
            return [$nearest, 'tag-ahead'];
        }

        $file = $this->repositoryPath().'/VERSION';

        if (is_file($file) && is_readable($file)) {
            $value = trim((string) @file_get_contents($file));

            if ($value !== '') {
                return [$value, 'file'];
            }
        }

        // Deliberately not '1.0.0' (config/app.php's default): that would claim a version
        // with nothing behind it.
        return [null, 'unknown'];
    }

    private function commit(): ?string
    {
        $head = $this->readGitFile('HEAD');

        if ($head === null) {
            return null;
        }

        // Detached HEAD (what an in-place update leaves): the file holds the hash itself.
        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) {
            return $head;
        }

        if (preg_match('#^ref:\s*(.+)$#', $head, $matches) !== 1) {
            return null;
        }

        $ref = trim($matches[1]);
        // Loose first, then packed: `git gc` / `git pack-refs` move a branch into
        // `.git/packed-refs` and delete its loose file.
        $hash = $this->readGitFile($ref) ?? $this->packedRef($ref);

        return $hash !== null && preg_match('/^[0-9a-f]{40}$/i', $hash) === 1 ? $hash : null;
    }

    /**
     * A ref's hash from `.git/packed-refs`: `<hash> <ref>` lines, `#` comment
     * lines, and `^<hash>` lines that peel the annotated tag above them.
     */
    private function packedRef(string $ref): ?string
    {
        $packed = $this->readGitFile('packed-refs');

        if ($packed === null) {
            return null;
        }

        foreach (preg_split('/\r?\n/', $packed) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/i', trim($line), $m) === 1 && $m[2] === $ref) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Null on a detached HEAD (an updated panel): there is no branch then.
     */
    private function branch(): ?string
    {
        $head = $this->readGitFile('HEAD');

        if ($head === null || preg_match('#^ref:\s*refs/heads/(.+)$#', $head, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Whether the panel runs from a git checkout at all — a clone, or a linked
     * worktree of one.
     */
    public function isGitCheckout(): bool
    {
        return $this->gitDir() !== null;
    }

    /**
     * The checkout's git directory.
     *
     * Usually `.git` itself; in a linked worktree (or submodule) `.git` is a file,
     * `gitdir: <path>`, naming the real one.
     */
    private function gitDir(): ?string
    {
        $dotGit = $this->repositoryPath().'/.git';

        if (is_dir($dotGit)) {
            return $dotGit;
        }

        if (! is_file($dotGit) || ! is_readable($dotGit)
            || preg_match('/^gitdir:\s*(.+)$/m', (string) @file_get_contents($dotGit), $m) !== 1) {
            return null;
        }

        $dir = trim($m[1]);
        $dir = str_starts_with($dir, '/') ? $dir : $this->repositoryPath().'/'.$dir;

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Where branches and `packed-refs` live. A worktree keeps only its own
     * HEAD; `commondir` points back at the repository that holds the rest.
     */
    private function commonDir(): ?string
    {
        $gitDir = $this->gitDir();

        if ($gitDir === null || ! is_file($gitDir.'/commondir')) {
            return $gitDir;
        }

        $common = trim((string) @file_get_contents($gitDir.'/commondir'));

        if ($common === '') {
            return $gitDir;
        }

        $common = str_starts_with($common, '/') ? $common : $gitDir.'/'.$common;

        return is_dir($common) ? $common : $gitDir;
    }

    /**
     * A file under the git directory. HEAD is the checkout's own; refs and
     * `packed-refs` are read from the common directory.
     */
    private function readGitFile(string $relative): ?string
    {
        $dir = $relative === 'HEAD' ? $this->gitDir() : $this->commonDir();

        if ($dir === null) {
            return null;
        }

        $path = $dir.'/'.ltrim($relative, '/');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
