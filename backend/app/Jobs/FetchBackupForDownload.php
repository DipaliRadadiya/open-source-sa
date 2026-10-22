<?php

namespace App\Jobs;

use App\Models\BackupDownloadPreparation;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Downloads\PreparedDownloads;
use App\Services\Server\Backups\Storage\ArtifactFetcher;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a backup off SFTP or FTP onto the panel, so the browser can have it.
 *
 * The same fetch a restore uses ({@see ArtifactFetcher}): straight to disk,
 * never through the adapters' `php://temp` buffer. The copy is kept for
 * `download_ttl_seconds` once ready.
 *
 * Not retried. A second attempt against a server that just refused the login
 * is one more failed login towards its fail2ban, and the user's next click
 * starts a fresh copy anyway. Dispatched to `default`, the queue the
 * installer's worker drains.
 */
class FetchBackupForDownload implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $preparationId)
    {
        $this->timeout = self::timeoutSeconds();
    }

    /** The backup job's budget: the same archive, travelling the other way. */
    public static function timeoutSeconds(): int
    {
        return (int) config('server.backups.job_timeout', 21600);
    }

    public function handle(PreparedDownloads $downloads, DestinationDisk $disks, StorageDriverFactory $drivers): void
    {
        $preparation = BackupDownloadPreparation::with('backup')->find($this->preparationId);

        // Removed (the backup was deleted) or already settled between being
        // queued and being picked up: nothing to do.
        if ($preparation === null || $preparation->status !== BackupDownloadPreparation::PREPARING || $preparation->backup === null) {
            return;
        }

        $backup = $preparation->backup;
        $destination = $backup->destination();
        $key = $backup->manifest['key'] ?? null;

        if ($destination === null || ! is_string($key) || $key === '') {
            $this->markFailed($preparation, 'missing', $downloads);

            return;
        }

        try {
            $disk = $disks->for($destination);

            if (! $disk->exists($key)) {
                $this->markFailed($preparation, 'missing', $downloads);

                return;
            }

            // The real size, now that the storage server can be asked: the
            // row's `size_bytes` is null on a failed run kept for forensics,
            // and that is exactly when the request could not check the disk.
            $remoteSize = $this->remoteSize($disk, $key);
            $expected = $remoteSize ?? $backup->size_bytes;

            if ($downloads->shortfall($expected) !== null) {
                $this->markFailed($preparation, 'no_space', $downloads);

                return;
            }

            $path = $downloads->prepareDirectory($preparation);
            $preparation->update(['path' => $path, 'size_bytes' => $expected]);

            (new ArtifactFetcher($disks, $drivers))->fetch($destination, $key, $path);

            clearstatcache(true, $path);
            $size = (int) @filesize($path);

            // A short copy is a broken download that would open as a corrupt
            // archive on someone's laptop. Said here, where it can be retried.
            // Compared with what the server reported, never with the row:
            // the row's figure is not always the object's own size.
            if ($remoteSize !== null && $size !== $remoteSize) {
                $this->markFailed($preparation, 'incomplete', $downloads);

                return;
            }
        } catch (Throwable $e) {
            $reason = $this->classify($drivers, $destination, $e);

            Log::channel('server-ops')->error('backup download preparation failed', [
                'feature' => 'backup',
                'backup' => $backup->getKey(),
                'reference' => $preparation->reference,
                'reason' => $reason,
                'detail' => $e->getMessage(),
            ]);

            $this->markFailed($preparation, $reason, $downloads);

            return;
        }

        $preparation->update([
            'status' => BackupDownloadPreparation::READY,
            'size_bytes' => $size,
            'ready_at' => now(),
            'expires_at' => now()->addSeconds(BackupDownloadPreparation::ttlSeconds()),
        ]);
    }

    /** The worker died or the timeout hit: say so, and free the disk. */
    public function failed(?Throwable $e): void
    {
        Log::channel('server-ops')->error('backup download preparation crashed', [
            'feature' => 'backup',
            'preparation' => $this->preparationId,
            'detail' => $e?->getMessage(),
        ]);

        $preparation = BackupDownloadPreparation::find($this->preparationId);

        if ($preparation !== null && $preparation->status === BackupDownloadPreparation::PREPARING) {
            $this->markFailed($preparation, 'crashed', app(PreparedDownloads::class));
        }
    }

    private function markFailed(BackupDownloadPreparation $preparation, string $reason, PreparedDownloads $downloads): void
    {
        // A partial copy is no use to anyone and may be most of a disk.
        File::deleteDirectory($downloads->directory($preparation));

        $preparation->update([
            'status' => BackupDownloadPreparation::FAILED,
            'reason' => $reason,
            'path' => null,
        ]);
    }

    /**
     * The driver's own category for what went wrong — `unreachable`,
     * `invalid_credentials`, `host_key_mismatch`… — the same names the
     * destination's Test button reports, so the two never disagree.
     */
    private function classify(StorageDriverFactory $drivers, StorageDestination $destination, Throwable $e): string
    {
        try {
            return Str::after($drivers->for($destination)->classify($e), 'storage.test.');
        } catch (Throwable) {
            return 'unreachable';
        }
    }

    private function remoteSize(Filesystem $disk, string $key): ?int
    {
        try {
            return (int) $disk->fileSize($key);
        } catch (Throwable) {
            return null;
        }
    }
}
