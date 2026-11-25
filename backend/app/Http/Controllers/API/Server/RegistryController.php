<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Docker\CreateRegistry;
use App\Actions\Server\Docker\DeleteRegistry;
use App\Actions\Server\Docker\TestRegistry;
use App\Actions\Server\Docker\UpdateRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Docker\StoreRegistryRequest;
use App\Http\Requests\Server\Docker\UpdateRegistryRequest;
use App\Http\Resources\RegistryResource;
use App\Models\Registry;
use App\Services\Server\Docker\RegistryAuthFailedException;
use Illuminate\Http\JsonResponse;

/**
 * The registry credentials this server can pull with.
 *
 * Server-level like the networks and volumes: it outlives any one container and is
 * chosen by many.
 *
 * Thin by design; the only judgement here is what an unwritable credential file means
 * to an HTTP caller.
 */
class RegistryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'registries' => RegistryResource::collection(
                // Counted, not loaded: the delete confirmation needs only the number of sites.
                Registry::withCount('applications')->orderBy('name')->get(),
            ),
        ]);
    }

    public function store(StoreRegistryRequest $request, CreateRegistry $action): JsonResponse
    {
        $registry = $action->execute($request->validated());

        return response()->json(['registry' => new RegistryResource($registry)], 201);
    }

    public function show(Registry $registry): JsonResponse
    {
        return response()->json([
            'registry' => new RegistryResource($registry->loadCount('applications')),
        ]);
    }

    public function update(UpdateRegistryRequest $request, Registry $registry, UpdateRegistry $action): JsonResponse
    {
        return response()->json([
            'registry' => new RegistryResource($action->execute($registry, $request->validated())->loadCount('applications')),
        ]);
    }

    public function destroy(Registry $registry, DeleteRegistry $action): JsonResponse
    {
        $action->execute($registry);

        return response()->json(['message' => __('errors/docker.registry_deleted')]);
    }

    /**
     * Ask the registry whether the stored credential works.
     *
     * A refused credential is **200 with a failed verdict**, not a 4xx: the request
     * itself succeeded and the verdict is persisted. Only the file-write failure is
     * a 500, because then the panel could not even ask.
     */
    public function test(Registry $registry, TestRegistry $action): JsonResponse
    {
        try {
            $tested = $action->execute($registry);
        } catch (RegistryAuthFailedException $e) {
            return response()->json([
                'message' => __('errors/docker.registry_credential_unwritable'),
                'reference' => $e->reference,
            ], 500);
        }

        return response()->json([
            'registry' => new RegistryResource($tested->loadCount('applications')),
            'success' => (bool) $tested->last_test_success,
        ]);
    }
}
