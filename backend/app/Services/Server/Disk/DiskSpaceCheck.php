<?php

namespace App\Services\Server\Disk;

use App\Models\BackupTarget;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Asked before an action that writes a lot to this server's disk is accepted
 * (2026-10-29, Dipali): a restore, a backup, a staging copy, a clone, a zip or
 * an unzip. If it would not fit, the request is refused there and then with
 * the numbers, and nothing is queued — rather than the user waiting for a job
 * that fills the disk, takes every site with it for a moment, and fails.
 *
 * The same rule as backups ({@see LocalSpaceGuard}): what is left afterwards
 * must be at least 10% of the disk or 2 GB, whichever is more. The jobs keep
 * their own checks too, because the disk can fill up between the two.
 *
 * Fails open: an unknown size (0) or a disk that cannot be measured is let
 * through, as everywhere else — "we could not tell" is not "it is full".
 */
class DiskSpaceCheck
{
    public function __construct(
        private LocalBackupFolder $folder,
        private LocalSpaceGuard $guard,
        private SizeEstimate $sizes,
    ) {}

    /**
     * Room for a backup of this target, which is built in the panel's working
     * folder whatever its destination.
     *
     * @throws ValidationException
     */
    public function requireForBackup(BackupTarget $target): void
    {
        $this->require((int) $this->sizes->backup($target), self::workingDirectory(), 'disk_space');
    }

    /** Where backups are built and restores unpacked. */
    public static function workingDirectory(): string
    {
        return (string) config('server.backups.working_dir', storage_path('app/backups'));
    }

    /**
     * Refuse with a 422 on `$field` when writing `$bytes` more on the disk
     * holding `$path` would leave less than the reserve.
     *
     * @throws ValidationException
     */
    public function require(int $bytes, string $path, string $field): void
    {
        if ($bytes <= 0) {
            return;
        }

        ['free' => $free, 'total' => $total] = $this->folder->space($this->existing($path));

        if ($free === null || $total === null) {
            return;
        }

        $usable = max(0, $free - $this->guard->reserve($total));

        if ($bytes <= $usable) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [__('errors/server.not_enough_disk_space', [
                'needed' => self::size($bytes),
                'free' => self::size($usable),
            ])],
        ]);
    }

    /** "15.2 GB", but "0 B" rather than "0.0 B" for a disk with nothing left. */
    private static function size(int $bytes): string
    {
        return Number::fileSize($bytes, $bytes >= 1024 ? 1 : 0);
    }

    /**
     * The nearest directory that exists, so a target not created yet is
     * measured on the disk it will be created on.
     */
    private function existing(string $path): string
    {
        while ($path !== '' && $path !== '/' && ! is_dir($path)) {
            $path = dirname($path);
        }

        return $path === '' ? '/' : $path;
    }
}
