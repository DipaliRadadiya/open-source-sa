<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\StorageDestination\CreateStorageDestination;
use App\Actions\Server\StorageDestination\DeleteStorageDestination;
use App\Actions\Server\StorageDestination\UpdateStorageDestination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\StorageDestination\StoreStorageDestinationRequest;
use App\Http\Requests\Server\StorageDestination\UpdateStorageDestinationRequest;
use App\Http\Resources\StorageDestinationResource;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use Illuminate\Http\JsonResponse;

class StorageDestinationController extends Controller
{
    public function index(): JsonResponse
    {
        $destinations = StorageDestination::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'storage_destinations' => StorageDestinationResource::collection($destinations)->resolve(),
        ]);
    }

    public function store(StoreStorageDestinationRequest $request, CreateStorageDestination $action): JsonResponse
    {
        $destination = $action->execute($request->validated());

        return response()->json([
            'storage_destination' => StorageDestinationResource::make($destination)->resolve(),
        ], 201);
    }

    public function show(StorageDestination $storageDestination): JsonResponse
    {
        return response()->json([
            'storage_destination' => StorageDestinationResource::make($storageDestination)->resolve(),
        ]);
    }

    public function update(
        UpdateStorageDestinationRequest $request,
        StorageDestination $storageDestination,
        UpdateStorageDestination $action,
    ): JsonResponse {
        $destination = $action->execute($storageDestination, $request->validated());

        return response()->json([
            'storage_destination' => StorageDestinationResource::make($destination)->resolve(),
        ]);
    }

    public function destroy(StorageDestination $storageDestination, DeleteStorageDestination $action): JsonResponse
    {
        $action->execute($storageDestination);

        return response()->json(null, 204);
    }

    /**
     * Probe an existing destination's credentials and reachability by
     * uploading/reading/deleting a sentinel object. Heavy and external, so
     * throttled — a slow S3-compatible endpoint must not become a way to
     * occupy the panel's request workers.
     */
    public function test(
        StorageDestination $storageDestination,
        StorageConnectionProber $prober,
    ): JsonResponse {
        $result = $prober->probe($storageDestination);

        return response()->json([
            // Use a verb-named wrapper (`test`) rather than the resource
            // shape — the resource describes stored config, this result
            // describes a live network probe.
            'test' => [
                'success' => $result['success'],
                'latency_ms' => $result['latency_ms'],
                'message' => $result['message'],
                'error_class' => $result['error_class'],
                // Local timestamp so the UI can show "Tested 3s ago"
                // even after the page is closed.
                'tested_at' => now()->format('d-m-Y H:i:s'),
            ],
            // Always 200. The request itself succeeded — we asked the panel
            // to probe a destination and it did, then told us what it found.
            // A 5xx here would make the frontend's error interceptor treat a
            // perfectly good answer as a server fault, and the body is not
            // the `code`-bearing error envelope the rest of the API returns
            // for real failures. `test.success` carries the verdict.
        ]);
    }
}
