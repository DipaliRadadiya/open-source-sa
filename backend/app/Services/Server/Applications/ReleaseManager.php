<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ReleaseOperationException;
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
 * Paths use the slug, not the domain: the slug is unique and stable, a domain can change.
 */
class ReleaseManager
{
    public function __construct(
        private ServerOps $serverOps,
    ) {}

    /** The application root (parent of `releases/`). */
    public function appRoot(Application $application): string
    {
        return $application->rootPath();
    }

    /** The path of the `current` symlink itself. */
    public function currentSymlinkPath(Application $application): string
    {
        return $this->appRoot($application).'/current';
    }

    /**
     * The `current` symlink resolved to its real target. Null when it does not exist yet.
     */
    public function currentPath(Application $application): ?string
    {
        $symlink = $this->currentSymlinkPath($application);

        if (! is_link($symlink)) {
            return null;
        }

        $target = readlink($symlink);

        if ($target === false || ! file_exists($symlink)) {
            return null;
        }

        return realpath($symlink) ?: null;
    }

    /** The path served right now (`current/public`); null without a `current` symlink. */
    public function servedPath(Application $application): ?string
    {
        $current = $this->currentPath($application);

        return $current !== null ? "{$current}/public" : null;
    }

    /** The web root inside a release: `releases/<timestamp>/public`. */
    public function releasePublicPath(string $releasePath): string
    {
        return rtrim($releasePath, '/').'/public';
    }

    /**
     * Create the app root and releases/ tree, seeding the first release from an existing
     * directory when migrating. Returns the new release path.
     */
    public function createAppStructure(Application $application, ?string $existingFilesPath = null): string
    {
        $root = $this->appRoot($application);
        $timestamp = now()->format('YmdHis');

        $this->run(['mkdir', '-p', "{$root}/releases/{$timestamp}/public"]);

        if ($existingFilesPath !== null && is_dir($existingFilesPath)) {
            $this->run([
                'cp', '-a',
                rtrim($existingFilesPath, '/').'/.',
                "{$root}/releases/{$timestamp}/public/",
            ]);
        }

        // Empty for now; the Environment screen manages it.
        $this->run(['touch', "{$root}/.env"]);

        return "{$root}/releases/{$timestamp}";
    }

    /**
     * Initialise the `current` symlink after creating the first release.
     */
    public function initialSymlink(Application $application, string $releasePath): void
    {
        $symlink = $this->currentSymlinkPath($application);

        // `-sf`, not `-sTf`: `T` is not needed while the symlink does not exist yet.
        $this->run(['ln', '-sf', "{$releasePath}", $symlink]);
    }

    /**
     * Create an empty release directory; the caller (GitDeployer) populates it. Returns the
     * release path, which the caller records on the Release model once the deploy completes.
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
     * The previous target is stored on the application so rollback can repoint to it.
     *
     * @param  string  $releasePath  Absolute path to the release (e.g. /home/user/app/releases/20260807100000)
     */
    public function activateRelease(Application $application, string $releasePath): void
    {
        $symlink = $this->currentSymlinkPath($application);

        $previousTarget = is_link($symlink) ? realpath($symlink) : null;

        // `-T` treats the link as a file (no link inside the old target dir), `-f` replaces it.
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

        $currentTarget = realpath($symlink);

        $this->run(['ln', '-sTf', $previousPath, $symlink]);

        // Mark the release we just left as rolled back, so the releases list can tell it from
        // the one serving traffic. Done here (not in a controller) so every caller gets it,
        // and matched by path: the only handle this method has on that release.
        if (is_string($currentTarget) && $currentTarget !== '') {
            $left = Release::query()
                ->where('application_id', $application->id)
                ->where(function ($query) use ($currentTarget) {
                    $query->where('path', $currentTarget)
                        ->orWhere('path', $this->releasePublicPath($currentTarget));
                })
                ->first();

            if ($left !== null) {
                $this->markRolledBack($left);
            }
        }

        // The new previous is what we just rolled back from.
        $application->updateQuietly([
            'previous_release_path' => $currentTarget,
        ]);

        return $previousPath;
    }

    /** False means an existing app that needs migrating on first deploy. */
    public function hasReleasesDirectory(Application $application): bool
    {
        return is_dir($this->appRoot($application).'/releases');
    }

    /** False for an orphan symlink (target deleted), which needs investigation. */
    public function currentSymlinkIsValid(Application $application): bool
    {
        $symlink = $this->currentSymlinkPath($application);

        return is_link($symlink) && is_dir($symlink);
    }

    /** Record a release in the database after a successful deploy. */
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

    /** Mark a release as rolled_back. */
    public function markRolledBack(Release $release): void
    {
        $release->update(['status' => 'rolled_back']);
    }

    /** Delete old releases beyond the keep limit, keeping the most recent N. */
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
    /**
     * Every command here must succeed: it throws on failure. A swallowed `mkdir` failure
     * left a vhost pointing at a directory that was never made and the site marked active.
     */
    private function run(array $args): ServerOpsResult
    {
        $result = $this->serverOps->run($args, [
            'feature' => 'release',
            'op' => $args[0],
        ]);

        if ($result->failed()) {
            throw new ReleaseOperationException($result->reference);
        }

        return $result;
    }
}
