<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Exceptions\NotEnoughBackupSpace;
use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Models\StorageDestination;
use App\Rules\LocalBackupPath;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * "This server": backups kept in a folder on the panel's own server (#14).
 *
 * Written through Laravel's local disk like any other destination, so
 * listing, retention, delete and restore need nothing of their own. Three
 * things do differ:
 *
 * - **Storing an archive is a move**, not a copy, when the folder is on the
 *   disk the archive was built on — instant, and no second copy of a
 *   multi-gigabyte file ever exists. On another disk it is copied.
 * - **The space is checked** before the archive is stored ({@see LocalSpaceGuard}).
 * - **Download is a signed panel link** that reads the file where it is.
 *
 * Off the server it is not a backup at all if the server or its disk dies;
 * the screen says so, and this class does not pretend otherwise.
 */
class LocalDriver implements StorageDriver
{
    public function __construct(
        private LocalBackupFolder $folder,
        private LocalSpaceGuard $space,
    ) {}

    public function provider(): StorageProvider
    {
        return StorageProvider::Local;
    }

    public function config(StorageDestination $destination): array
    {
        return [
            'driver' => 'local',
            'root' => $this->root($destination),
            // Private all the way down: backups hold every site's database.
            'permissions' => [
                'file' => ['public' => 0600, 'private' => 0600],
                'dir' => ['public' => 0700, 'private' => 0700],
            ],
            'throw' => true,
        ];
    }

    public function rules(bool $requireSecrets = true): array
    {
        return [
            'config.path' => ['required', 'string', 'max:255', new LocalBackupPath],
        ];
    }

    public function secretKeys(): array
    {
        return [];
    }

    public function publicConfig(StorageDestination $destination): array
    {
        $path = (string) $destination->configValue('path');
        $space = $this->folder->space($path);

        return [
            'path' => $path,
            // Shown so the user sees the disk filling before it is full.
            'free_bytes' => $space['free'],
            'total_bytes' => $space['total'],
            'used_bytes' => $this->folder->used($path),
            'same_server' => true,
        ];
    }

    public function classify(Throwable $e): string
    {
        return match (true) {
            $e instanceof LocalBackupFolderException => str_starts_with($e->key, 'storage.test.') ? $e->key : 'storage.test.local_not_writable',
            $e instanceof NotEnoughBackupSpace => 'storage.test.local_no_space',
            default => 'storage.test.local_not_writable',
        };
    }

    /**
     * The folder must already be there and be the panel's. Never created
     * here: one that has gone missing may be a disk that is not mounted, and
     * recreating it would put the backups on the system disk instead.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        return $this->folder->problem((string) $destination->configValue('path'));
    }

    public function heal(StorageDestination $destination): void {}

    /**
     * For a restore: a hard link into the working folder when it is on the
     * same disk — instant, and no second copy. Anything else falls back to
     * the ordinary streamed copy.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        $source = $this->file($destination, $key);

        return $source !== null && @link($source, $path);
    }

    /**
     * A panel link, signed and short-lived, that reads the archive where it
     * is. Relative, like the prepared-download link: the browser reaches the
     * panel through whatever host it already uses.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return url(URL::temporarySignedRoute(
            'storage.local.download',
            now()->addMinutes(5),
            ['storageDestination' => $destination->getKey(), 'key' => $key],
            absolute: false,
        ));
    }

    /**
     * Store the archive: check the space against its real size, then move it
     * in (same disk) or copy it (another disk), and hand it to the panel,
     * private.
     *
     * @throws NotEnoughBackupSpace
     */
    public function uploadFrom(StorageDestination $destination, string $key, string $path, ?callable $onProgress = null): bool
    {
        $root = $this->root($destination);

        if (($problem = $this->folder->problem((string) $destination->configValue('path'))) !== null) {
            throw new LocalBackupFolderException($problem);
        }

        $size = (int) filesize($path);
        $sameDisk = $this->folder->sameDisk($path, $root);

        // On the same disk the archive already takes its space, and moving it
        // adds nothing: the disk must just not already be below the reserve.
        // On another disk the whole archive is still to be written there.
        $this->space->ensureRoomFor($root, $sameDisk ? 0 : $size);

        $target = $root.'/'.ltrim($key, '/');
        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('could not create the backup folder '.$directory);
        }

        if ($sameDisk) {
            if (! @rename($path, $target)) {
                throw new RuntimeException('could not move the archive into the backup folder');
            }
        } else {
            // A partial copy must never look like a finished archive.
            $partial = $target.'.part';

            if (! @copy($path, $partial) || filesize($partial) !== $size || ! @rename($partial, $target)) {
                @unlink($partial);

                throw new RuntimeException('could not copy the archive into the backup folder');
            }
        }

        $this->folder->adopt($target);

        if ($onProgress !== null) {
            $onProgress($size);
        }

        return true;
    }

    /**
     * The archive's real path, or null when the key would leave the folder or
     * names nothing. The key comes from a signed link, but it is checked here
     * as though it did not.
     */
    public function file(StorageDestination $destination, string $key): ?string
    {
        $root = realpath($this->root($destination));
        $file = realpath($this->root($destination).'/'.ltrim($key, '/'));

        if ($root === false || $file === false || ! str_starts_with($file, $root.'/') || ! is_file($file)) {
            return null;
        }

        return $file;
    }

    private function root(StorageDestination $destination): string
    {
        $path = rtrim((string) $destination->configValue('path'), '/');
        $prefix = trim((string) $destination->prefix, '/');

        return $prefix === '' ? $path : $path.'/'.$prefix;
    }
}
