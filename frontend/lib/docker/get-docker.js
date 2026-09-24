import { read } from "@/lib/api/read";
import {
  dockerNetworksResponseSchema,
  dockerVolumesResponseSchema,
} from "@/lib/schemas/docker";

/**
 * Networks and volumes together, because the page shows both.
 *
 * A failed request must not degrade to an empty list: "this server has no
 * networks" is a claim about the machine, and Docker always has at least
 * three. Reporting it without having heard from the machine would be a lie
 * that looks like data.
 */
export async function getDockerResources() {
  const [networks, volumes] = await Promise.all([
    read("/docker/networks", dockerNetworksResponseSchema),
    read("/docker/volumes", dockerVolumesResponseSchema),
  ]);

  return {
    networks: networks.failed ? [] : (networks.data?.networks ?? []),
    volumes: volumes.failed ? [] : (volumes.data?.volumes ?? []),
    failed: networks.failed || volumes.failed,
    status: networks.status ?? volumes.status,
    failure: networks.failure ?? volumes.failure,
    message: networks.message ?? volumes.message,
  };
}
