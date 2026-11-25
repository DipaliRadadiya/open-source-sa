<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\PrepareBackupDownload;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupDownloadPreparationResource;
use App\Models\Backup;
use App\Models\BackupDownloadPreparation;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\Downloads\PreparedDownloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Download for backups kept on SFTP or FTP: prepare, then download.
 *
 * Those providers have no link a browser can follow, so `GET …/download`
 * answers 409 `prepare_required` for them. The archive is copied to the panel
 * on the queue, polled here until ready, and then served from the panel's own
 * disk through a signed link that lasts as long as the copy does.
 */
class BackupDownloadController extends Controller
{
    /**
     * Start preparing, or return the preparation already under way.
     *
     * 202 when this call started the copy, 200 when one was already preparing
     * or ready — a second click never starts a second transfer.
     */
    public function store(Request $request, Backup $backup, PrepareBackupDownload $action): JsonResponse
    {
        ['preparation' => $preparation, 'created' => $created] = $action->execute($backup, $request->user());

        return response()->json([
            'download_preparation' => BackupDownloadPreparationResource::make($preparation->setRelation('backup', $backup))->resolve(),
        ], $created ? 202 : 200);
    }

    /** Polled while the copy is made. Null when none was ever started. */
    public function show(Backup $backup): JsonResponse
    {
        $preparation = $backup->downloadPreparation;

        return response()->json([
            'download_preparation' => $preparation === null
                ? null
                : BackupDownloadPreparationResource::make($preparation->setRelation('backup', $backup))->resolve(),
        ]);
    }

    /**
     * The prepared copy itself, sent from the panel's own disk.
     *
     * Each send holds a PHP worker for the whole transfer, so only a few run at once;
     * one more gets 503 and a retry hint instead of waiting with a connection open.
     *
     * Logged as `backup.downloaded` when the file starts going out, not when the link is
     * made. A resumed download (a `Range` past the start) is not logged again.
     */
    public function file(Request $request, Backup $backup, PreparedDownloads $downloads, ActivityLogger $activity): BinaryFileResponse|JsonResponse
    {
        $preparation = BackupDownloadPreparation::query()
            ->where('backup_id', $backup->getKey())
            ->where('reference', (string) $request->query('preparation'))
            ->first();

        if ($preparation === null || ! $preparation->isReady()) {
            return response()->json(['message' => __('backup.errors.download_not_ready'), 'reason' => 'not_ready'], 410);
        }

        $slot = $downloads->acquireSlot();

        if ($slot === null) {
            return response()->json(['message' => __('backup.errors.download_busy'), 'reason' => 'busy'], 503, ['Retry-After' => '30']);
        }

        // Freed after the response, and at shutdown too: a client that hangs up mid-transfer
        // ends the script before `terminating` runs. Releasing twice is harmless.
        $release = static fn () => $slot->release();
        app()->terminating($release);
        register_shutdown_function($release);

        // A multi-gigabyte send outlives any request time limit.
        @set_time_limit(0);

        $range = (string) $request->headers->get('Range', '');

        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            // Records who took a copy of the site's files and database.
            $activity->log('backup.downloaded', $backup, [
                'application' => $backup->subjectName(),
                'backup_id' => $backup->getKey(),
            ]);
        }

        $response = new BinaryFileResponse($preparation->path, 200, [
            // Never sniffed: an archive is a download, not something to render.
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'private, no-store',
        ], public: false, autoEtag: false, autoLastModified: false);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->downloadFilename());
    }
}
