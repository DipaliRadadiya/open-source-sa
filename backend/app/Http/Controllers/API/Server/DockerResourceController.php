<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Server\Docker\DockerResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Docker's networks and volumes.
 *
 * The refusals live here rather than in the service, because they are about
 * what the panel is willing to do rather than about how to do it — and each
 * one exists because Docker's own answer is worse than nothing:
 *
 *  - A network with containers on it: `docker network rm` says "has active
 *    endpoints" and names neither the network nor what is on it, leaving the
 *    user to go and run docker themselves to find out.
 *  - A volume in use: `docker volume rm` refuses, but `-f` does not, and the
 *    panel must never be the thing that deletes a running database.
 *  - `bridge`, `host`, `none`: Docker recreates them on restart, so a delete
 *    is a control that either fails or does damage.
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

    public function createNetwork(Request $request, DockerResources $docker): JsonResponse
    {
        $name = (string) $request->input('name');

        abort_unless(DockerResources::validName($name), 422, __('errors/docker.invalid_name'));

        // Asked before creating rather than reading Docker's error after:
        // "network with name X already exists" is fine for a terminal and
        // wrong for a form, which wants to say which field is at fault.
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

        // Named, not counted. "It is in use" sends someone to the terminal;
        // "uptime-kuma-1 is on it" tells them what to stop.
        abort_if(
            $network['containers'] !== [],
            409,
            __('errors/docker.network_in_use', [
                'name' => $name,
                'containers' => implode(', ', $network['containers']),
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

        // The one that matters. `docker volume rm -f` would do this happily,
        // and a volume is where a container's data lives — deleting one that
        // is attached is deleting a running database.
        abort_if(
            $volume['in_use'],
            409,
            __('errors/docker.volume_in_use', [
                'name' => $name,
                'count' => (string) $volume['containers'],
            ]),
        );

        $result = $docker->removeVolume($name);

        abort_if($result->failed(), 500, __('errors/docker.volume_remove_failed', [
            'reference' => $result->reference,
        ]));

        return response()->json(['volumes' => $docker->volumes()]);
    }
}
