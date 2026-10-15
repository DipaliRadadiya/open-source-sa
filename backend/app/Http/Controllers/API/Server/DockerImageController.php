<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Docker\DockerImageRequest;
use App\Http\Requests\Server\Docker\SearchDockerImagesRequest;
use App\Services\Server\Docker\Images\ImageInspector;
use App\Services\Server\Docker\Images\ImageLookupException;
use App\Services\Server\Docker\Images\ImageSearch;
use App\Services\Server\Docker\Images\ImageTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Image discovery for the Docker create form (DS-02): search Docker Hub, list
 * an image's versions, and read what an image needs — all from registry APIs,
 * never by pulling.
 *
 * Two kinds of "no" are kept apart. "No such image" is an answer about the
 * image and comes back 200 with `found: false`, because the form shows it next
 * to the field. "We could not ask the registry" is not a verdict on anything:
 * search and tags degrade to empty lists marked `offline`, and inspect answers
 * 503 so the form falls back to asking the user for the port.
 */
class DockerImageController extends Controller
{
    public function search(SearchDockerImagesRequest $request, ImageSearch $search): JsonResponse
    {
        return response()->json($search->search((string) $request->validated('q'), (int) ($request->validated('limit') ?? 10)));
    }

    public function tags(DockerImageRequest $request, ImageTags $tags): JsonResponse
    {
        try {
            return response()->json($tags->list($request->reference(), $request->credential(), (int) ($request->validated('limit') ?? 20)));
        } catch (ImageLookupException) {
            // Only a refused host reaches here; every other failure is an
            // empty list the picker understands.
            return $this->blockedHost();
        }
    }

    public function inspect(DockerImageRequest $request, ImageInspector $inspector): JsonResponse
    {
        $image = $request->reference();

        try {
            return response()->json($inspector->inspect($image, $request->credential()));
        } catch (ImageLookupException $e) {
            if ($e->reason === ImageLookupException::BLOCKED_HOST) {
                return $this->blockedHost();
            }

            Log::channel('server-ops')->warning('docker image inspect failed', [
                'image' => $image->full(),
                'reason' => $e->reason,
                'detail' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->reason === ImageLookupException::RATE_LIMITED
                    ? __('docker.image.rate_limited')
                    : __('docker.image.registry_unreachable', ['registry' => $image->registry]),
                'reason' => $e->reason,
            ], 503);
        }
    }

    /**
     * A registry address the panel never connects to — a refusal of the
     * field, so it is shaped like one.
     */
    private function blockedHost(): JsonResponse
    {
        return response()->json([
            'message' => __('docker.image.blocked_host'),
            'errors' => ['image' => [__('docker.image.blocked_host')]],
        ], 422);
    }
}
