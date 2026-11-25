<?php

namespace App\Services\Server\Backups\Storage;

use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;

/**
 * The folder a "This server" backup destination writes to.
 *
 * Backups hold every file of a site and a database dump, so the folder is the panel's own and
 * private (0700): a site user must never read another site's database out of it.
 *
 * - Only strictly below a configured root (`/var/backups`, `/mnt`, `/media`); no symlink on the
 *   path may lead elsewhere.
 * - Never somebody else's folder: a missing one is created, an existing one is used only if it
 *   is the panel's or empty. One holding another account's files is refused.
 * - Created one level deep, never recreated later. With `mkdir -p` an unmounted disk would get
 *   its mount point invented on the system disk, and backups would quietly fill it.
 */
class LocalBackupFolder
{
    public function __construct(
        private ServerOps $serverOps,
        private HomeDirectoryAccess $access,
    ) {}

    /**
     * The path without a trailing slash; null unless it is a plain absolute path.
     */
    public function normalise(string $path): ?string
    {
        $path = rtrim(trim($path), '/');

        if (! preg_match('#^(/[A-Za-z0-9._-]+)+$#', $path)) {
            return null;
        }

        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * Why this path may not be used, as a `storage.local.*` key, or null. Only checks for links.
     */
    public function pathProblem(string $raw): ?string
    {
        $path = $this->normalise($raw);

        if ($path === null) {
            return 'storage.local.path_invalid';
        }

        $allowed = false;
        foreach ($this->roots() as $root) {
            if (str_starts_with($path, $root.'/')) {
                $allowed = true;
            }
        }

        if (! $allowed) {
            return 'storage.local.path_not_allowed';
        }

        // Every component, the folder included: a link anywhere could point it at /etc.
        $walk = '';
        foreach (explode('/', substr($path, 1)) as $segment) {
            $walk .= '/'.$segment;

            if (is_link($walk)) {
                return 'storage.local.path_link';
            }
        }

        return null;
    }

    /**
     * What is at the path: `missing`, `parent_missing`, `ours`, `empty` (someone else's but empty),
     * `foreign` (someone else's, not empty) or `not_folder`.
     *
     * Asked with elevation: "could not look" must not read as "missing".
     */
    public function state(string $path): string
    {
        $stat = $this->serverOps->run(
            ['stat', '-c', '%U|%F', $path],
            ['feature' => 'backup', 'op' => 'local_folder_stat'],
            timeout: 15,
            expectedExitCodes: [1],
        );

        if ($stat->failed() || trim($stat->output()) === '') {
            if ($stat->failed() && ! str_contains(strtolower($stat->errorOutput()), 'no such file')) {
                throw new LocalBackupFolderException('storage.local.unreadable', $stat->reference);
            }

            return is_dir(dirname($path)) ? 'missing' : 'parent_missing';
        }

        [$owner, $type] = array_pad(explode('|', trim($stat->output()), 2), 2, '');

        if ($type !== 'directory') {
            return 'not_folder';
        }

        if ($owner === $this->access->panelAccount()) {
            return 'ours';
        }

        $first = $this->serverOps->run(
            ['find', $path, '-mindepth', '1', '-maxdepth', '1', '-print', '-quit'],
            ['feature' => 'backup', 'op' => 'local_folder_empty'],
            timeout: 15,
        );

        if ($first->failed()) {
            throw new LocalBackupFolderException('storage.local.unreadable', $first->reference);
        }

        return trim($first->output()) === '' ? 'empty' : 'foreign';
    }

    /**
     * Make the folder ready on save: create it or take over an empty one, closed to everyone
     * but the panel. Returns `created` or `existing`.
     *
     * @throws LocalBackupFolderException naming why not
     */
    public function prepare(string $raw): string
    {
        $problem = $this->pathProblem($raw);

        if ($problem !== null) {
            throw new LocalBackupFolderException($problem, parameters: ['roots' => implode(', ', $this->roots())]);
        }

        $path = (string) $this->normalise($raw);
        $state = $this->state($path);

        match ($state) {
            'parent_missing' => throw new LocalBackupFolderException('storage.local.parent_missing', parameters: ['parent' => dirname($path)]),
            'foreign' => throw new LocalBackupFolderException('storage.local.folder_foreign'),
            'not_folder' => throw new LocalBackupFolderException('storage.local.not_folder'),
            default => null,
        };

        if ($state === 'missing') {
            // One level: the parent is known to exist (see the class note).
            $this->run(['mkdir', '-m', '0700', $path], 'local_folder_create');
        }

        if ($state !== 'ours') {
            $this->run(['chown', $this->access->panelAccount(), $path], 'local_folder_chown');
        }

        $this->run(['chmod', '0700', $path], 'local_folder_chmod');

        return $state === 'missing' ? 'created' : 'existing';
    }

    /**
     * Why a saved folder cannot be used now, as a `storage.test.*` key, or null. Never creates.
     */
    public function problem(string $raw): ?string
    {
        if (($problem = $this->pathProblem($raw)) !== null) {
            return str_replace('storage.local.', 'storage.test.local_', $problem);
        }

        return match ($this->state((string) $this->normalise($raw))) {
            'ours' => null,
            'missing', 'parent_missing' => 'storage.test.local_missing',
            default => 'storage.test.local_foreign',
        };
    }

    /**
     * Free and total bytes of the folder's disk; nulls when the folder is gone.
     *
     * @return array{free: int|null, total: int|null}
     */
    public function space(string $path): array
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return [
            'free' => $free === false ? null : (int) $free,
            'total' => $total === false ? null : (int) $total,
        ];
    }

    /**
     * Bytes the backups in the folder use.
     */
    public function used(string $path): ?int
    {
        if (! is_dir($path) || ! is_readable($path)) {
            return null;
        }

        $bytes = 0;

        try {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return $bytes;
    }

    /**
     * Whether two paths are on the same filesystem, so a rename moves a file without copying.
     */
    public function sameDisk(string $a, string $b): bool
    {
        $da = @stat($a);
        $db = @stat($b);

        return $da !== false && $db !== false && $da['dev'] === $db['dev'];
    }

    /**
     * Hand a moved-in file to the panel, private: a root-owned archive cannot be hard-linked
     * for a restore (protected_hardlinks).
     */
    public function adopt(string $file): void
    {
        $this->run(['chown', $this->access->panelAccount(), $file], 'local_file_chown');
        $this->run(['chmod', '0600', $file], 'local_file_chmod');
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return array_values(array_map(
            fn ($root) => rtrim((string) $root, '/'),
            (array) config('server.backups.local.allowed_roots', ['/var/backups', '/mnt', '/media']),
        ));
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $op): void
    {
        $result = $this->serverOps->run($command, ['feature' => 'backup', 'op' => $op], timeout: 30);

        if ($result->failed()) {
            throw new LocalBackupFolderException('storage.local.prepare_failed', $result->reference);
        }
    }
}
