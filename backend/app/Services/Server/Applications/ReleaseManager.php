<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Models\Release;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Manages the release directory lifecycle: creation, symlink swap, and rollback.
 *
 * Directory structure:
 *   /home/<user>/<app_slug>/
 *     .env                          ← shared, never inside a release
 *     releases/
 *       <timestamp>/                ← one per deploy, immutable after creation
 *         public/                  ← the web root
 *           (application files)
 *     current -> releases/<timestamp>   ← symlink, atomically swapped
 *
 * Slug-based paths (not domain-based) — the slug is unique and stable, whereas
 * a domain can be changed and must not break the filesystem path.
 */
class ReleaseManager
{
    public function __construct(
        private ServerOps $serverOps,
    ) {}

    /**
     * The absolute path to the application root (parent of `releases/`).
     */
    public function appRoot(Application $application): string
    {
        return $application->rootPath();
    }

    /**
     * The absolute path to the `current` symlink target.
     */
    public function currentSymlinkPath(Application $application): string
    {
        return $this->appRoot($application).'/current';
    }

    /**
     * The absolute path nginx and PHP-FPM actually serve from — the
     * `current` symlink resolved to its real target. Null when `current`
     * does not exist yet (brand new provisioning).
     */
    public function currentPath(Application $application): ?string
    {
        $symlink = $this->currentSymlinkPath($application);

        if (! is_link($symlink)) {
            return null;
        }

        $target = readlink($symlink);

        // readlink returns the raw symlink value. Resolve it relative to the
        // symlink's parent directory so a relative target works correctly.
        if ($target === false || ! file_exists($symlink)) {
            return null;
        }

        return realpath($symlink) ?: null;
    }

    /**
     * The absolute path served by nginx/PHP right now — `current/public`.
     * Null when no `current` symlink exists.
     */
    public function servedPath(Application $application): ?string
    {
        $current = $this->currentPath($application);

        return $current !== null ? "{$current}/public" : null;
    }

    /**
     * The web root inside a release — `releases/<timestamp>/public`.
     */
    public function releasePublicPath(string $releasePath): string
    {
        return rtrim($releasePath, '/').'/public';
    }

    /**
     * Create the app root and releases directory structure, creating the
     * initial release from an existing directory (migration case).
     *
     * Called when provisioning a brand-new app, or when the first release-based
     * deploy hits an app that has files but no releases/ directory.
     *
     * Returns the path to the newly created release.
     */
    public function createAppStructure(Application $application, ?string $existingFilesPath = null): string
    {
        $root = $this->appRoot($application);
        $timestamp = now()->format('YmdHis');

        // Create the directory tree.
        $this->run(['mkdir', '-p', "{$root}/releases/{$timestamp}/public"]);

        // Migrate existing files if this is an existing app with files.
        if ($existingFilesPath !== null && is_dir($existingFilesPath)) {
            $this->run([
                'cp', '-a',
                rtrim($existingFilesPath, '/').'/.',
                "{$root}/releases/{$timestamp}/public/",
            ]);
        }

        // Create the .env file (empty for now; the Environment screen manages it).
        $this->run(['touch', "{$root}/.env"]);

        return "{$root}/releases/{$timestamp}";
    }

    /**
     * Initialise the `current` symlink after creating the first release.
     */
    public function initialSymlink(Application $application, string $releasePath): void
    {
        $symlink = $this->currentSymlinkPath($application);

        // `ln -sf` (not -sTf): initialise case creates a new symlink with force.
        // `T` is not needed here since the target does not exist yet.
        $this->run(['ln', '-sf', "{$releasePath}", $symlink]);
    }

    /**
     * Create a new release directory and populate it via git.
     *
     * The directory is created empty; the caller (GitDeployer) populates it
     * with git clone/reset-hard and the build script.
     *
     * Returns the release path. The caller records it on the Release model
     * once the deploy is complete.
     */
    public function prepareRelease(Application $application): string
    {
        $root = $this->appRoot($application);
        $timestamp = now()->format('YmdHis');

        $this->run(['mkdir', '-p', "{$root}/releases/{$timestamp}/public"]);

        return "{$root}/releases/{$timestamp}";
    }

    /**
     * Atomically activate a release by swapping the `current` symlink.
     *
     * Called after a successful deploy. The previous symlink target is stored
     * in the application record so rollback can repoint to it.
     *
     * @param  string  $releasePath  Absolute path to the release (e.g. /home/user/app/releases/20260807100000)
     */
    public function activateRelease(Application $application, string $releasePath): void
    {
        $symlink = $this->currentSymlinkPath($application);

        // Record the current symlink target as the previous release path,
        // so rollback can return to it.
        $previousTarget = is_link($symlink) ? realpath($symlink) : null;

        // `ln -sTf`: -s (symbolic), -T (treat target as file, no dir loop),
        // -f (force: remove existing current). Atomic on Linux filesystems.
        $this->run(['ln', '-sTf', $releasePath, $symlink]);

        $application->updateQuietly([
            'previous_release_path' => $previousTarget,
        ]);
    }

    /**
     * Roll back to the previous release by repointing the `current` symlink.
     *
     * Idempotent: running twice rolls back two generations.
     *
     * @throws \RuntimeException when there is no previous release to roll back to
     */
    public function rollback(Application $application): string
    {
        $symlink = $this->currentSymlinkPath($application);

        if (! is_link($symlink)) {
            throw new \RuntimeException('No current release to roll back from.');
        }

        $previousPath = $application->previous_release_path;

        if ($previousPath === null || ! is_dir($previousPath)) {
            throw new \RuntimeException('No previous release to roll back to.');
        }

        // Capture the current target before overwriting it.
        $currentTarget = realpath($symlink);

        // Atomic swap.
        $this->run(['ln', '-sTf', $previousPath, $symlink]);

        // The new previous is what we just rolled back from.
        $application->updateQuietly([
            'previous_release_path' => $currentTarget,
        ]);

        return $previousPath;
    }

    /**
     * Returns true when the app has a releases directory.
     * False means this is an existing app that needs migration on first deploy.
     */
    public function hasReleasesDirectory(Application $application): bool
    {
        return is_dir($this->appRoot($application).'/releases');
    }

    /**
     * Whether the current symlink exists and points to a directory that still
     * exists on disk. An orphan symlink (target deleted) means something went
     * wrong and needs investigation.
     */
    public function currentSymlinkIsValid(Application $application): bool
    {
        $symlink = $this->currentSymlinkPath($application);

        return is_link($symlink) && is_dir($symlink);
    }

    /**
     * Record a release in the database after a successful deploy.
     */
    public function recordRelease(
        Application $application,
        string $path,
        ?string $commitHash,
        ?string $output,
    ): Release {
        return Release::create([
            'application_id' => $application->id,
            'path' => $path,
            'commit_hash' => $commitHash,
            'committed_at' => now(),
            'deployed_at' => now(),
            'status' => 'active',
            'output' => $output,
        ]);
    }

    /**
     * Mark the currently-active release as rolled_back.
     */
    public function markRolledBack(Release $release): void
    {
        $release->update(['status' => 'rolled_back']);
    }

    /**
     * Delete old releases beyond the keep limit, keeping the most recent N.
     */
    public function prune(Application $application, int $keep = 10): int
    {
        $keep = max(1, $keep);

        $toDelete = Release::query()
            ->where('application_id', $application->id)
            ->where('status', '!=', 'active')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(1)
            ->value('id');

        if ($toDelete === null) {
            return 0;
        }

        $deleted = Release::query()
            ->where('application_id', $application->id)
            ->where('id', '<=', $toDelete)
            ->where('status', '!=', 'active')
            ->delete();

        return $deleted;
    }

    /**
     * Run a command via ServerOps.
     *
     * @param  array<int, string>  $args
     */
    private function run(array $args): ServerOpsResult
    {
        return $this->serverOps->run($args, [
            'feature' => 'release',
            'op' => $args[0],
        ]);
    }
}
