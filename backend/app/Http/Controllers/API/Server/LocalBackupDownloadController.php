<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\StorageProvider;
use App\Http\Controllers\Controller;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\Drivers\LocalDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The file behind a "This server" backup's download link (#14).
 *
 * Read where it is — never copied first, so downloading a 40 GB archive does
 * not need another 40 GB. The link is signed and expires in minutes; the
 * `backups.download` endpoint that hands it out has already checked the
 * backup, logged the download and found the file.
 */
class LocalBackupDownloadController extends Controller
{
    public function show(Request $request, StorageDestination $storageDestination, LocalDriver $driver): BinaryFileResponse|JsonResponse
    {
        abort_unless($storageDestination->provider === StorageProvider::Local, 404);

        $key = (string) $request->query('key');
        $file = $driver->file($storageDestination, $key);

        if ($file === null) {
            return response()->json(['message' => __('backup.errors.download_missing')], 410);
        }

        // A multi-gigabyte send outlives any request time limit.
        @set_time_limit(0);

        $response = new BinaryFileResponse($file, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'private, no-store',
        ], public: false, autoEtag: false, autoLastModified: false);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($file));
    }
}
