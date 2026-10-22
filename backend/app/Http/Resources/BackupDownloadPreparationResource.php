<?php

namespace App\Http\Resources;

use App\Models\BackupDownloadPreparation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * One prepared download (SFTP/FTP), as the Download button polls it.
 *
 * `status` is what the screen switches on: `preparing` (show progress from
 * `bytes_transferred` of `bytes_total`), `ready` (offer `url` until
 * `expires_at`), `failed` (show `message`, and `reference` for support).
 * A stranded or expired copy reads as `expired`: the next click starts over.
 *
 * @mixin BackupDownloadPreparation
 */
class BackupDownloadPreparationResource extends JsonResource
{
    /**
     * Storage-side reasons are the categories the destination's Test button
     * reports, explained in the same words; the rest are this feature's own.
     */
    private const OWN_REASONS = [
        'missing' => 'backup.errors.download_missing',
        'no_space' => 'backup.errors.download_prepare_no_space',
        'incomplete' => 'backup.errors.download_prepare_incomplete',
        'crashed' => 'backup.errors.download_prepare_crashed',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = match (true) {
            $this->isPreparing() => BackupDownloadPreparation::PREPARING,
            $this->isReady() => BackupDownloadPreparation::READY,
            $this->status === BackupDownloadPreparation::FAILED => BackupDownloadPreparation::FAILED,
            default => 'expired',
        };

        return [
            'reference' => $this->reference,
            'status' => $status,
            'filename' => $this->backup?->downloadFilename(),
            'bytes_total' => $this->size_bytes,
            // How far the copy has got, read off the file itself: neither
            // adapter reports progress, and the file is the honest answer.
            'bytes_transferred' => $status === BackupDownloadPreparation::PREPARING ? $this->bytesOnDisk() : null,
            'url' => $status === BackupDownloadPreparation::READY ? $this->url() : null,
            'expires_at' => $status === BackupDownloadPreparation::READY ? $this->expires_at?->format('d-m-Y H:i:s') : null,
            'reason' => $status === BackupDownloadPreparation::FAILED ? $this->reason : null,
            'message' => match ($status) {
                BackupDownloadPreparation::FAILED => $this->message(),
                'expired' => __('backup.errors.download_not_ready'),
                default => null,
            },
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'ready_at' => $this->ready_at?->format('d-m-Y H:i:s'),
        ];
    }

    private function message(): string
    {
        $reason = (string) $this->reason;

        if (isset(self::OWN_REASONS[$reason])) {
            return __(self::OWN_REASONS[$reason]);
        }

        $detail = 'storage.test.'.$reason;

        return __('backup.errors.download_prepare_failed', [
            'detail' => __($detail) === $detail ? __('storage.test.failure') : __($detail),
        ]);
    }

    /**
     * Signed, and valid until the copy is removed. Relative, so it verifies
     * whichever host name the browser reached the panel by; made absolute
     * for the browser with the host of this very request.
     */
    private function url(): string
    {
        return url(URL::temporarySignedRoute(
            'backups.download.file',
            $this->expires_at,
            ['backup' => $this->backup_id, 'preparation' => $this->reference],
            absolute: false,
        ));
    }

    private function bytesOnDisk(): ?int
    {
        if (! is_string($this->path) || ! is_file($this->path)) {
            return null;
        }

        clearstatcache(true, $this->path);

        return (int) filesize($this->path);
    }
}
