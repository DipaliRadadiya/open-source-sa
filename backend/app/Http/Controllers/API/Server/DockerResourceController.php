<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\Docker\NetworkIsolation;
use App\Services\Server\HostCpus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Docker's networks and volumes.
 *
 * The refusals live here, not in the service: they are about what the panel is willing to do.
 *
 *  - A network with containers on it: Docker's "has active endpoints" names nothing.
 *  - A volume in use: `docker volume rm -f` would delete it; the panel must never delete a
 *    running database.
 *  - `bridge`, `host`, `none`: Docker recreates them on restart, so a delete only fails or harms.
 */
class DockerResourceController extends Controller
{
    public function networks(DockerResources $docker): JsonResponse
    {
        return response()->json(['networks' => $docker->networks()]);
    }

    public function volumes(DockerResources $docker): JsonResponse
    {
        return response()->json(['volumes' => $docker->volumes()]);
    }

    /**
     * What this machine can be asked for, so a limit field can show real values.
     *
     * The CPU field is bounded server-side by this count, so the hint must match it.
     *
     * Its own endpoint, not a field on the **unauthenticated** `/basic-info`: the size of
     * the server is not for anonymous visitors. Gated on `docker` (view).
     *
     * Defaults come with it: an empty memory field means 512m, an empty CPU field means no limit.
     */
    public function limits(HostCpus $cpus): JsonResponse
    {
        return response()->json([
            'limits' => [
                'cpus' => $cpus->count(),
                'default_memory_limit' => (string) config('server.docker.default_memory_limit', '512m'),
                'default_db_memory_limit' => (string) config('server.docker.default_db_memory_limit', '512m'),
            ],
        ]);
    }

    public function createNetwork(Request $request, DockerResources $docker): JsonResponse
    {
        $name = (string) $request->input('name');

        abort_unless(DockerResources::validName($name), 422, __('errors/docker.invalid_name'));

        // The `sv-app-<id>_*` namespace marks a site's private network; one created here under
        // that name would be found by Compose without its labels.
        $reserved = NetworkIsolation::reservedName($name);

        abort_if($reserved !== null, 422, (string) $reserved);

        // Checked first, so the form can name the field at fault instead of showing Docker's error.
        $existing = collect($docker->networks())->firstWhere('name', $name);

        abort_if($existing !== null, 422, __('errors/docker.network_exists', ['name' => $name]));

        $result = $docker->createNetwork($name);

        abort_if($result->failed(), 500, __('errors/docker.network_create_failed', [
            'reference' => $result->reference,
        ]));

        return response()->json(['networks' => $docker->networks()], 201);
    }

    public function removeNetwork(string $name, DockerResources $docker): JsonResponse
    {
        $network = collect($docker->networks())->firstWhere('name', $name);

        abort_if($network === null, 404);

        abort_if(
            $network['built_in'],
            422,
            __('errors/docker.network_built_in', ['name' => $name]),
        );

        // Name the containers so the user knows what to stop.
        abort_if(
            $network['containers'] !== [],
            409,
            __('errors/docker.network_in_use', [
                'name' => $name,
                'containers' => implode(', ', array_column($network['containers'], 'name')),
            ]),
        );

        // A STOPPED site is attached to nothing, so the checks above pass, yet its compose file
        // still names this network as `external: true` and the site would not start again.
        abort_if(
            $network['sites'] !== [],
            409,
            __('errors/docker.network_used_by_sites', [
                'name' => $name,
                'sites' => implode(', ', array_column($network['sites'], 'name')),
            ]),
        );

        $result = $docker->removeNetwork($name);

        abort_if($result->failed(), 500, __('errors/docker.network_remove_failed', [
            'reference' => $result->reference,
        ]));

        return response()->json(['networks' => $docker->networks()]);
    }

    public function createVolume(Request $request, DockerResources $docker): JsonResponse
    {
        $name = (string) $request->input('name');

        abort_unless(DockerResources::validName($name), 422, __('errors/docker.invalid_name'));

        $existing = collect($docker->volumes())->firstWhere('name', $name);

        abort_if($existing !== null, 422, __('errors/docker.volume_exists', ['name' => $name]));

        $result = $docker->createVolume($name);

        abort_if($result->failed(), 500, __('errors/docker.volume_create_failed', [
            'reference' => $result->reference,
        ]));

        return response()->json(['volumes' => $docker->volumes()], 201);
    }

    public function removeVolume(string $name, DockerResources $docker): JsonResponse
    {
        $volume = collect($docker->volumes())->firstWhere('name', $name);

        abort_if($volume === null, 404);

        // A volume holds a container's data; deleting an attached one deletes a running database.
        // Named when the panel could name the containers, counted when it could not (empty names
        // beside a non-zero `Links` means `docker inspect` did not answer). Refused either way.
        abort_if(
            $volume['in_use'],
            409,
            $volume['container_names'] !== []
                ? __('errors/docker.volume_in_use_by', [
                    'name' => $name,
                    'containers' => implode(', ', $volume['container_names']),
                ])
                : __('errors/docker.volume_in_use', [
                    'name' => $name,
                    'count' => (string) $volume['containers'],
                ]),
        );

        // A STOPPED site has no container, so `Links` is 0 and the checks above pass while the
        // volume still holds its data. Deleting it would destroy the site's database.
        abort_if(
            $volume['sites'] !== [],
            409,
            __('errors/docker.volume_used_by_sites', [
                'name' => $name,
                'sites' => implode(', ', array_column($volume['sites'], 'name')),
            ]),
        );

        $result = $docker->removeVolume($name);

        abort_if($result->failed(), 500, __('errors/docker.volume_remove_failed', [
            'reference' => $result->reference,
        ]));

        return response()->json(['volumes' => $docker->volumes()]);
    }
}
