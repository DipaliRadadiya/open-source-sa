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

/**
 * Just the networks, for the picker on a container site's settings.
 *
 * Degrades to an empty list on failure here, unlike `getDockerResources()`
 * above, and the difference is deliberate: this feeds a chooser beside a saved
 * value, so "we could not ask" and "there are none" both mean the same thing to
 * it — offer nothing new and keep showing what the site already has. The page
 * that makes claims about the machine is the one that must not guess.
 */
export async function getDockerNetworks() {
  const networks = await read("/docker/networks", dockerNetworksResponseSchema);

  return networks.failed ? [] : (networks.data?.networks ?? []);
}
