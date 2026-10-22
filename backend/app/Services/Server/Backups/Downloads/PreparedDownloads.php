<?php

namespace App\Services\Server\Backups\Downloads;

use App\Models\Backup;
use App\Models\BackupDownloadPreparation;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Where prepared downloads live on this server, and how they are kept in check.
 *
 * One private directory per copy (`{download_dir}/{reference}`, 0700, file
 * 0600), owned by the panel account the queue worker runs as. Never under a
 * site's home: the copy is the whole site plus its database, and the site's
 * own user must not be able to read it.
 */
class PreparedDownloads
{
    /** How long a send may hold its slot before the slot frees itself. */
    private const SLOT_SECONDS = 6 * 3600;

    public function root(): string
    {
        return rtrim((string) config('server.backups.download_dir', storage_path('app/backup-downloads')), '/');
    }

    public function directory(BackupDownloadPreparation $preparation): string
    {
        return $this->root().'/'.$preparation->reference;
    }

    /** The path the archive is fetched to; the directory is made private first. */
    public function prepareDirectory(BackupDownloadPreparation $preparation): string
    {
        File::ensureDirectoryExists($this->root(), 0700);
        @chmod($this->root(), 0700);

        $directory = $this->directory($preparation);
        File::ensureDirectoryExists($directory, 0700);
        @chmod($directory, 0700);

        $path = $directory.'/archive.tar.gz';

        // Created empty and narrowed before a byte arrives: the fetch opens it
        // with `wb`, which keeps an existing file's mode.
        touch($path);
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Bytes free where the copies are written, or null when that cannot be
     * read — unknown is not "full", and refusing on it would break Download
     * on a box whose filesystem simply does not answer.
     */
    public function freeBytes(): ?int
    {
        $directory = $this->root();

        // The directory may not exist yet; its nearest existing parent is on
        // the same filesystem, which is the question.
        while (! is_dir($directory) && $directory !== dirname($directory)) {
            $directory = dirname($directory);
        }

        $free = @disk_free_space($directory);

        return $free === false ? null : (int) $free;
    }

    /**
     * Whether a copy of this size fits, with the margin the rest of the
     * server needs left over. Null size (not recorded) is not refused here;
     * the job checks again once it knows the real size.
     *
     * @return array{needed: int, free: int}|null null when it fits
     */
    public function shortfall(?int $sizeBytes): ?array
    {
        $free = $this->freeBytes();

        if ($sizeBytes === null || $free === null) {
            return null;
        }

        $needed = $sizeBytes + max(0, (int) config('server.backups.download_free_margin_bytes', 1024 ** 3));

        return $free < $needed ? ['needed' => $needed, 'free' => $free] : null;
    }

    /**
     * One of the few slots for sending a copy, or null when all are taken.
     *
     * Each send holds a PHP worker for the whole transfer, so the number in
     * flight is capped rather than left to whoever clicks. A lock per slot
     * rather than a counter: a lock frees itself when its holder dies, and a
     * counter that missed a decrement would lock Download out for good.
     */
    public function acquireSlot(): ?Lock
    {
        $slots = max(1, (int) config('server.backups.download_concurrency', 2));

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock('backup-download-slot:'.$slot, self::SLOT_SECONDS);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    /** Remove a copy and its row. */
    public function discard(BackupDownloadPreparation $preparation): void
    {
        File::deleteDirectory($this->directory($preparation));

        $preparation->delete();
    }

    /** Remove the copy a backup's download left, before the backup goes. */
    public function forget(Backup $backup): void
    {
        BackupDownloadPreparation::query()
            ->where('backup_id', $backup->getKey())
            ->get()
            ->each(fn (BackupDownloadPreparation $preparation) => $this->discard($preparation));
    }

    /**
     * Expired copies, failed rows past their hour, preparations whose worker
     * died — and any directory with no row at all (its backup was removed by
     * a cascade, which runs no model hook).
     *
     * @return int how many copies were removed
     */
    public function prune(): int
    {
        $removed = 0;

        BackupDownloadPreparation::query()->stale()->chunkById(100, function ($preparations) use (&$removed): void {
            foreach ($preparations as $preparation) {
                $this->discard($preparation);
                $removed++;
            }
        });

        if (! is_dir($this->root())) {
            return $removed;
        }

        foreach (File::directories($this->root()) as $directory) {
            // Asked per directory, not from one list taken up front: a copy
            // started after that list was read would be swept mid-download.
            // A directory is only ever made after its row, so no row now
            // means none is coming.
            if (! BackupDownloadPreparation::query()->where('reference', basename($directory))->exists()) {
                File::deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }
}
