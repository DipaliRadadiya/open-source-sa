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
 * Resumable uploads of arbitrary size, for files past what the single-shot endpoint on
 * ApplicationFileController can buffer.
 *
 * Chunks arrive as a raw body, not multipart: multipart makes PHP spool each part to a temp file
 * first, doubling disk traffic. A raw body is streamed from `php://input` straight to the write.
 * So there is no `$request->file()` and no size rule in a FormRequest; size is bounded by nginx's
 * `client_max_body_size` (one chunk) and the free-space guard in ChunkedUpload.
 *
 * Activity is logged once, on finalize, not per chunk.
 */
class ApplicationUploadController extends Controller
{
    /**
     * How much the disk can take, so the client can refuse a file when it is picked.
     *
     * Advisory only: `begin` and every chunk re-check server-side, as the free space changes.
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
            // What this server takes per chunk; exceeding post_max_size is a 413 before this runs.
            'max_chunk' => ChunkedUpload::maxChunkBytes(),
        ]);
    }

    /** Appends one chunk and reports the new total, which confirms what landed. */
    public function chunk(
        Request $request,
        Application $application,
        string $uploadId,
        ChunkedUpload $uploads,
    ): JsonResponse {
        // `getContent(true)` rather than fopen('php://input'): the same stream under FPM, but it
        // also works when the request is built synthetically (tests).
        $stream = $request->getContent(true);

        abort_unless(is_resource($stream), 400);

        try {
            $received = $uploads->append($application, $uploadId, $stream);
        } finally {
            fclose($stream);
        }

        return response()->json(['received' => $received]);
    }

    /** Bytes stored so far, so an interrupted client resumes from the server's offset. */
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
        string $uploadId,
        ChunkedUpload $uploads,
        ActivityLogger $activity,
    ): JsonResponse {
        $uploads->finalize($application, $uploadId, $request->targetPath());

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
