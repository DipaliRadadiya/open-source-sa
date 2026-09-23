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
    public function lock(Application $application): string
    {
        $path = $this->path($application);

        if ($path === null) {
            return self::UNSAFE;
        }

        $before = $this->inspect($application, $path);

        if ($before === null) {
            return self::MISSING;
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
        $root = rtrim($application->rootPath(), '/');

        if ($home === '' || $root === '' || $root === $home) {
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
