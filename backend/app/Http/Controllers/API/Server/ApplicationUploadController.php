<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\BeginUploadRequest;
use App\Http\Requests\Server\Application\FinalizeUploadRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ChunkedUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumable uploads of arbitrary size, for files past what the single-shot
 * endpoint on ApplicationFileController can buffer.
 *
 * Chunks arrive as a **raw body**, not multipart. That is deliberate and is
 * half the point of the design: multipart makes PHP spool the part to its own
 * temp file before the handler runs, which doubles the disk traffic of every
 * upload on a box that is also serving customer sites. A raw body is read
 * straight off `php://input` as a stream and piped to the write, so a chunk is
 * never written to disk twice and never held in PHP memory at all.
 *
 * Consequently there is no `$request->file()` here and no size rule in a
 * FormRequest — the body is not form data. Size is bounded by nginx's
 * `client_max_body_size` (one chunk, not one file) and by the free-space guard
 * in ChunkedUpload.
 *
 * Activity is logged once, on finalize. Logging per chunk would write
 * thousands of rows for a single user action and bury the audit trail.
 */
class ApplicationUploadController extends Controller
{
    /**
     * How much the disk can take, so the client can refuse a file at the
     * moment it is picked rather than partway through sending it.
     *
     * Advisory only — `begin` and every chunk re-check server-side, because a
     * client's idea of the free space is stale the moment it is answered and
     * other sites on the same disk are writing throughout.
     */
    public function space(Application $application, ChunkedUpload $uploads): JsonResponse
    {
        return response()->json($uploads->space($application));
    }

    public function begin(
        BeginUploadRequest $request,
        Application $application,
        ChunkedUpload $uploads,
    ): JsonResponse {
        return response()->json([
            'upload_id' => $uploads->begin(
                $application,
                $request->targetPath(),
                $request->expectedBytes(),
            ),
        ]);
    }

    /**
     * Appends one chunk and reports the new total, which doubles as the
     * client's confirmation of what actually landed.
     */
    public function chunk(
        Request $request,
        Application $application,
        string $uploadId,
        ChunkedUpload $uploads,
    ): JsonResponse {
        // `getContent(true)` rather than fopen('php://input'): it is the same
        // unbuffered stream under FPM, but it goes through the request object,
        // so the body is also readable when one is set synthetically — reading
        // the raw SAPI stream directly is untestable and silently empty
        // anywhere the request was not built by the web server.
        $stream = $request->getContent(true);

        abort_unless(is_resource($stream), 400);

        try {
            $received = $uploads->append($application, $uploadId, $stream);
        } finally {
            fclose($stream);
        }

        return response()->json(['received' => $received]);
    }

    /**
     * Bytes stored so far, so an interrupted client can resume from the offset
     * the server actually has rather than trusting its own record of it.
     */
    public function status(
        Application $application,
        string $uploadId,
        ChunkedUpload $uploads,
    ): JsonResponse {
        return response()->json(['received' => $uploads->status($application, $uploadId)]);
    }

    public function finalize(
        FinalizeUploadRequest $request,
        Application $application,
        ChunkedUpload $uploads,
        ActivityLogger $activity,
    ): JsonResponse {
        $uploads->finalize($application, $request->uploadId(), $request->targetPath());

        $activity->log('application.file_uploaded', $application, [
            'name' => $application->name,
            'path' => $request->targetPath(),
        ]);

        return response()->json(['uploaded' => true]);
    }

    public function abort(
        Application $application,
        string $uploadId,
        ChunkedUpload $uploads,
    ): JsonResponse {
        $uploads->abort($application, $uploadId);

        return response()->json(['aborted' => true]);
    }
}
