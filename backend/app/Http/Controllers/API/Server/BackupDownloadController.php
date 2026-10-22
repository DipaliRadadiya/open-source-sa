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
     * The prepared copy itself.
     *
     * The one place the panel sends an archive's bytes, and only ever from
     * its own disk. Each send holds a PHP worker for the whole transfer, so
     * only a few run at once; one more is told to try again shortly rather
     * than queued behind them with a connection open.
     *
     * Recorded as `backup.downloaded` when the file starts going out — not
     * when the link is made, which is only an offer. A resumed download
     * (a `Range` past the start) is the same download, and is not logged again.
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

        // Freed when the response has been sent, and — because a client that
        // hangs up mid-transfer ends the script before `terminating` runs —
        // at shutdown as well. Releasing twice is harmless.
        $release = static fn () => $slot->release();
        app()->terminating($release);
        register_shutdown_function($release);

        // A multi-gigabyte send outlives any request time limit.
        @set_time_limit(0);

        $range = (string) $request->headers->get('Range', '');

        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            // Who took a copy of every file on the site and its database. The
            // link is not recorded: it works for as long as the copy exists.
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
