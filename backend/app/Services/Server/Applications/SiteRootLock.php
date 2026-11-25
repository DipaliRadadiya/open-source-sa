<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a site's root directory from being renamed or replaced by the site's own user.
 *
 * `{home}/{slug}` (and `.panel`, `logs` inside it) is owned by root, but it sits in the user's
 * HOME and renaming an entry needs write permission on the parent only. So the site user, or
 * any code running as it, could move the real directory aside and put a substitute (e.g. one
 * carrying a symlink the panel would then write and chown through, or a PHP config without
 * `disable_functions`) in its place.
 *
 * `chattr +i` closes that at the filesystem: an immutable directory cannot be renamed or
 * removed, and no entry can be added or removed, even by root until the flag is lifted.
 * Subdirectory contents are unaffected, so the site keeps serving.
 *
 * Lift the flag only for top-level changes ({@see unlocked()}), and never apply it to anything
 * that is not the real site root, which {@see lock()} checks first.
 */
class SiteRootLock
{
    public const LOCKED = 'locked';

    /** The filesystem has no immutable attribute (ZFS, some container overlays). */
    public const UNSUPPORTED = 'unsupported';

    /** Not the directory the panel created: a link, not root's, or the whole home. */
    public const UNSAFE = 'unsafe';

    public const MISSING = 'missing';

    public const FAILED = 'failed';

    /** Owned by an account that is neither root nor the site's own user. */
    public const FOREIGN_OWNER = 'foreign_owner';

    /** Writable by its group or by everyone: an open lock would not hold. */
    public const WRITABLE = 'writable';

    /** Handing the directory to root would shut its own user out of it. */
    public const LOCKS_OUT_USER = 'locks_out_user';

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Make the site root immutable, after checking it is the real one.
     *
     * The checks are the point: locking a substituted directory would pin it in place and
     * report the site as safe. The root must be a real directory (`stat` does not follow a
     * final symlink), owned by root, and not the user's home (an immutable home stops the user
     * creating any file).
     *
     * The inode is read before and after so a swap between the check and the `chattr` is
     * caught: the flag comes off and the site is reported, not trusted.
     */
    public function lock(Application $application, ?string $expectedInode = null): string
    {
        $path = $this->path($application);

        if ($path === null) {
            return self::UNSAFE;
        }

        $before = $this->inspect($application, $path);

        if ($before === null) {
            return self::MISSING;
        }

        // `adopt()` hands over the inode it checked; a directory swapped in since is refused.
        if ($expectedInode !== null && $before['inode'] !== $expectedInode) {
            $this->warn($application, $path, 'site root changed before it could be locked', $before);

            return self::UNSAFE;
        }

        if (! $this->trustworthy($before)) {
            $this->warn($application, $path, 'site root is not a root-owned directory', $before);

            return self::UNSAFE;
        }

        $result = $this->serverOps->run(['chattr', '+i', $path], $this->context($application, 'lock'), timeout: 15);

        if ($result->failed()) {
            return $this->unsupported($result->errorOutput()) ? self::UNSUPPORTED : self::FAILED;
        }

        $after = $this->inspect($application, $path);

        if ($after === null || $after['inode'] !== $before['inode'] || ! $this->trustworthy($after)) {
            $this->serverOps->run(['chattr', '-i', $path], $this->context($application, 'lock_undo'), timeout: 15);
            $this->warn($application, $path, 'site root changed while it was being locked', $after ?? []);

            return self::UNSAFE;
        }

        return self::LOCKED;
    }

    /**
     * Bring a site root the panel did not create (an adopted site) into the panel's layout,
     * owned by root, and lock it. `lock()` refuses a user-owned root because it cannot tell an
     * adopted site from a substitute; this is where the user vouches for it, with these checks:
     *
     *  - only ownership changes, with `chown -h` (never follows a symlink). No `chmod`: it has
     *    no such option, so a root swapped for a link would have root chmod the link's target;
     *  - the mode stays as is and must already work with root as owner: the user keeps access
     *    through the group, and neither group nor others may write, or top-level entries
     *    (`.panel`, `logs`) could be replaced while the flag is lifted;
     *  - the inode is compared across the chown and handed to `lock()`, so a directory swapped
     *    in part-way is never locked;
     *  - if the lock cannot be set, ownership is handed back.
     *
     * Returns LOCKED or the reason it was left as it is.
     */
    public function adopt(Application $application): string
    {
        $path = $this->path($application);
        $username = (string) $application->systemUser?->username;

        if ($path === null || $username === '') {
            return self::UNSAFE;
        }

        $before = $this->details($application, $path);

        if ($before === null) {
            return self::MISSING;
        }

        if ($before['type'] !== 'directory') {
            $this->warn($application, $path, 'site root to adopt is not a directory', $before);

            return self::UNSAFE;
        }

        // Already the panel's layout: nothing to hand over.
        if ($before['owner'] === 'root') {
            return $this->lock($application, $before['inode']);
        }

        if ($before['owner'] !== $username) {
            return self::FOREIGN_OWNER;
        }

        $mode = $before['mode'];

        if (($mode & 0o022) !== 0) {
            return self::WRITABLE;
        }

        if (($mode & 0o050) !== 0o050 || ! $this->inGroup($application, $username, $before['group'])) {
            return self::LOCKS_OUT_USER;
        }

        $chown = $this->serverOps->run(['chown', '-h', 'root', $path], $this->context($application, 'adopt'), timeout: 15);

        if ($chown->failed()) {
            return self::FAILED;
        }

        $after = $this->details($application, $path);

        if ($after === null || $after['inode'] !== $before['inode'] || $after['type'] !== 'directory') {
            // Not handed back: whatever is at the path now was never checked, and giving an
            // unknown root-owned entry to the site user is worse than leaving it.
            $this->warn($application, $path, 'site root changed while it was being adopted', $after ?? []);

            return self::UNSAFE;
        }

        $locked = $this->lock($application, $before['inode']);

        if ($locked === self::UNSUPPORTED || $locked === self::FAILED) {
            $current = $this->details($application, $path);

            if ($current !== null && $current['inode'] === $before['inode'] && $current['type'] === 'directory') {
                $this->serverOps->run(['chown', '-h', $username, $path], $this->context($application, 'adopt_undo'), timeout: 15);
            }
        }

        return $locked;
    }

    /**
     * Lift the flag for good: the site is being deleted, or its files are left to the user.
     */
    public function unlock(Application $application): void
    {
        $path = $this->path($application);

        if ($path === null || $this->inspect($application, $path) === null) {
            return;
        }

        $this->serverOps->run(['chattr', '-i', $path], $this->context($application, 'unlock'), timeout: 15);
    }

    /**
     * Run an operation that changes an entry at the top of the site root, with the flag lifted
     * only while it runs.
     *
     * Re-locked in `finally` so a throwing operation does not leave the site open, and only if
     * it was locked going in.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function unlocked(Application $application, callable $operation): mixed
    {
        $wasLocked = $this->isLocked($application);

        if ($wasLocked) {
            $this->unlock($application);
        }

        try {
            return $operation();
        } finally {
            if ($wasLocked) {
                try {
                    $this->lock($application);
                } catch (Throwable $exception) {
                    // The operation's own exception must not be replaced by the re-lock's.
                    Log::warning('site root could not be re-locked', [
                        'application' => $application->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Create a directory at the top of the site root if it is missing.
     *
     * `mkdir -p` on an existing directory succeeds without touching the parent, so it is tried
     * as-is first and the flag is lifted only when that fails.
     *
     * @param  array<string, mixed>  $context
     */
    public function ensureDirectory(Application $application, string $directory, array $context): ServerOpsResult
    {
        $made = $this->serverOps->run(['mkdir', '-p', $directory], $context, timeout: 15);

        if ($made->ok) {
            return $made;
        }

        return $this->unlocked(
            $application,
            fn () => $this->serverOps->run(['mkdir', '-p', $directory], $context, timeout: 15),
        );
    }

    /** Null when it cannot be told: no path, or `lsattr` could not answer. */
    public function isLocked(Application $application): ?bool
    {
        $path = $this->path($application);

        if ($path === null) {
            return null;
        }

        $result = $this->serverOps->run(['lsattr', '-d', $path], $this->context($application, 'status'), timeout: 15);

        if ($result->failed()) {
            return null;
        }

        $flags = strtok(trim($result->output()), " \t") ?: '';

        return str_contains($flags, 'i');
    }

    /** The site root, or null when there is none to lock (no user, or the root is the home). */
    private function path(Application $application): ?string
    {
        $home = rtrim((string) $application->systemUser?->home_path, '/');

        if ($home === '') {
            return null;
        }

        $root = rtrim($application->rootPath(), '/');

        if ($root === '' || $root === $home) {
            return null;
        }

        return $root;
    }

    /**
     * `stat` without -L reports a symlink as a symlink, so "is this the real directory" is
     * answerable.
     *
     * @return array{type: string, owner: string, inode: string}|null
     */
    private function inspect(Application $application, string $path): ?array
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%F|%U|%i', $path],
            $this->context($application, 'inspect'),
            timeout: 15,
            // A root that does not exist yet is normal before a new site's first provision.
            expectedExitCodes: [1],
        );

        if ($result->failed()) {
            return null;
        }

        $parts = explode('|', trim($result->output()));

        if (count($parts) !== 3) {
            return null;
        }

        return ['type' => $parts[0], 'owner' => $parts[1], 'inode' => $parts[2]];
    }

    /**
     * `inspect()` plus the group and mode `adopt()` has to judge.
     *
     * @return array{type: string, owner: string, group: string, mode: int, inode: string}|null
     */
    private function details(Application $application, string $path): ?array
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%F|%U|%G|%a|%i', $path],
            $this->context($application, 'inspect'),
            timeout: 15,
            expectedExitCodes: [1],
        );

        if ($result->failed()) {
            return null;
        }

        $parts = explode('|', trim($result->output()));

        if (count($parts) !== 5 || preg_match('/^[0-7]{3,4}$/', $parts[3]) !== 1) {
            return null;
        }

        return [
            'type' => $parts[0],
            'owner' => $parts[1],
            'group' => $parts[2],
            'mode' => octdec($parts[3]) & 0o777,
            'inode' => $parts[4],
        ];
    }

    /** Whether the account is a member of the group, primary or supplementary. */
    private function inGroup(Application $application, string $username, string $group): bool
    {
        $result = $this->serverOps->run(['id', '-nG', $username], $this->context($application, 'groups'), timeout: 15);

        return $result->ok && in_array($group, preg_split('/\s+/', trim($result->output())) ?: [], true);
    }

    /** @param  array{type: string, owner: string, inode: string}  $stat */
    private function trustworthy(array $stat): bool
    {
        return $stat['type'] === 'directory' && $stat['owner'] === 'root';
    }

    /** What chattr says on a filesystem without the attribute; anything else is a real failure. */
    private function unsupported(string $stderr): bool
    {
        return str_contains($stderr, 'Operation not supported')
            || str_contains($stderr, 'Inappropriate ioctl');
    }

    /** @param  array<string, mixed>  $stat */
    private function warn(Application $application, string $path, string $reason, array $stat): void
    {
        Log::channel('server-ops')->warning($reason, [
            'feature' => 'site_root_lock',
            'application' => $application->id,
            'path' => $path,
            'stat' => $stat,
        ]);
    }

    /** @return array<string, mixed> */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'site_root_lock', 'op' => $op, 'application' => $application->id];
    }
}
