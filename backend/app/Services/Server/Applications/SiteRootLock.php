<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a site's root directory from being renamed or replaced by the site's
 * own user.
 *
 * `{home}/{slug}` is owned by root, and so are `.panel` (the locked PHP config
 * lives there) and `logs` inside it. That ownership protected nothing on its
 * own: the directory sits in the user's HOME, which the user owns, and renaming
 * an entry needs write permission on the parent only. A site user — or any code
 * running as one, a compromised plugin included, since PHP's `rename()` and
 * `symlink()` are not disabled — could move the real directory aside and put
 * their own in its place. Reproduced on a real server (2026-09-23): the
 * substitute carried a symlink, an admin enabled Basic Auth, and the panel
 * wrote and chowned *the link's target* — a root-owned file became the site
 * user's. The same swap hands PHP a config without `disable_functions`.
 *
 * The immutable attribute (`chattr +i`) closes it at the filesystem: an
 * immutable directory cannot be renamed or removed, and no entry can be added
 * to or removed from it — by its owner, or by root, until the flag is lifted,
 * which needs CAP_LINUX_IMMUTABLE. Contents of the subdirectories are
 * unaffected, so the site keeps serving and PHP keeps writing its sessions.
 *
 * The flag has to be lifted for the few operations that change the top level
 * of the directory — {@see unlocked()} — and must never be applied to something
 * that is not the real site root, which is what {@see lock()} checks first.
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
     * The checks are the point. Locking a directory the site user substituted
     * would pin their substitute in place and report the site as safe. So the
     * root must be a real directory (`stat` does not follow a final symlink),
     * owned by root, and not the user's home — rows from before slugs resolve
     * to the home itself, and an immutable home is a user who can no longer
     * create a file anywhere.
     *
     * The inode is read before and after: a swap between the check and the
     * `chattr` would otherwise lock the substitute. If it moved, the flag comes
     * off whatever was locked and the site is reported, not trusted.
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

        // `adopt()` hands over the inode it checked, so a directory swapped in
        // between its chown and this stat is refused rather than locked.
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
     * Bring a site root the panel did not create into the panel's layout —
     * owned by root — and lock it. The Lock button on a site that server sync
     * adopted.
     *
     * Such a root belongs to the site's user, because somebody else set the
     * site up, and `lock()` rightly refuses a directory that is not root's: it
     * cannot tell an adopted site from a substitute. This is where the user
     * vouches for it, and where the checks that make that safe live:
     *
     *  - only ownership changes, and with `chown -h`, which never follows a
     *    symlink. No `chmod`: it has no such option, so a root swapped for a
     *    link at the wrong moment would have root change the mode of whatever
     *    the link points at;
     *  - so the mode stays as it is, and must already work with root as the
     *    owner: the user keeps access through the directory's group, and
     *    neither the group nor everyone else may write to it, or entries at
     *    the top (`.panel`, `logs`) could be replaced whenever the flag is
     *    lifted for an operation;
     *  - the inode is compared across the chown and handed to `lock()`, so a
     *    directory swapped in part-way is never locked;
     *  - if the lock itself cannot be set, ownership is handed back: a root
     *    the user can no longer write to and that is not locked either is a
     *    change with nothing to show for it.
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
            // Belt and braces: lock() compares the same inode and would refuse
            // this too. Checked here so the refusal comes before anything else
            // runs against the path. Not handed back: whatever is at the path
            // now is not what was checked, and giving an unknown root-owned
            // entry to the site user is the one outcome worse than leaving it.
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
     * Lift the flag for good — the site is being deleted, or its files are
     * being left to the user.
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
     * Run an operation that adds, removes or replaces an entry at the top of
     * the site root, with the flag lifted for exactly as long as it takes.
     *
     * Re-locked in `finally`, so an operation that throws does not leave the
     * site open. Only re-locked if it was locked going in: a site the panel
     * could not lock (unsupported filesystem, or flagged unsafe) is not
     * silently locked by an unrelated edit.
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
                    // Never let the re-lock replace the operation's own
                    // exception — that one is the answer the caller needs.
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
     * `mkdir -p` on a directory that already exists succeeds without touching
     * the parent, so it is tried as-is first and the flag is lifted only when
     * that fails — every vhost apply would otherwise open and close the lock
     * for a directory that has been there for months.
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

    /**
     * Null when it cannot be told — no path, or `lsattr` could not answer.
     */
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

    /**
     * The site root, or null when there is none to lock — a row without a
     * user, or one whose root resolves to the home directory itself.
     */
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
     * `stat` without -L reports a symlink as a symlink, which is what makes
     * "is this the real directory" answerable at all.
     *
     * @return array{type: string, owner: string, inode: string}|null
     */
    private function inspect(Application $application, string $path): ?array
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%F|%U|%i', $path],
            $this->context($application, 'inspect'),
            timeout: 15,
            // A root that does not exist yet is the normal answer before a new
            // site's first provision — `stat` exits 1 for it, and without this
            // every site creation put an ERROR on the admin dashboard.
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
     * `inspect()` plus the group and mode `adopt()` has to judge. A separate
     * read so the format every other caller (and test) relies on stays put.
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

    /**
     * What chattr says on a filesystem without the attribute: ioctl refused
     * outright, or the flag not supported. Anything else is a real failure.
     */
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
