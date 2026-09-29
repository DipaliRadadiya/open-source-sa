import { read } from "@/lib/api/read";
import {
  dockerNetworksResponseSchema,
  dockerVolumesResponseSchema,
  registriesResponseSchema,
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
  const [networks, volumes, registries] = await Promise.all([
    read("/docker/networks", dockerNetworksResponseSchema),
    read("/docker/volumes", dockerVolumesResponseSchema),
    read("/docker/registries", registriesResponseSchema),
  ]);

  return {
    networks: networks.failed ? [] : (networks.data?.networks ?? []),
    volumes: volumes.failed ? [] : (volumes.data?.volumes ?? []),
    // Degrades to an empty list rather than failing the page, unlike the two
    // above. "This server has no networks" is a lie about the machine; "no
    // registry credentials are stored" is the true and ordinary state of every
    // server that has not configured one, so an empty list here is honest.
    registries: registries.failed ? [] : (registries.data?.registries ?? []),
    failed: networks.failed || volumes.failed,
    status: networks.status ?? volumes.status,
    failure: networks.failure ?? volumes.failure,
    message: networks.message ?? volumes.message,
  };
}

/**
 * Just the registries, for the picker on a container site's settings.
 *
 * Degrades to an empty list, like the two below: this feeds a chooser beside a
 * saved value, and "we could not ask" and "there are none" both mean offer
 * nothing new and keep showing what the site already has.
 */
export async function getRegistries() {
  const registries = await read("/docker/registries", registriesResponseSchema);

  return registries.failed ? [] : (registries.data?.registries ?? []);
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

/**
 * Just the volumes, for the mount list on a container site's settings.
 *
 * Degrades to an empty list on failure, like `getDockerNetworks()` — this feeds a
 * chooser beside values the site already has, and "we could not ask" and "there
 * are none" both mean: offer nothing new, keep showing what is configured.
 */
export async function getDockerVolumes() {
  const volumes = await read("/docker/volumes", dockerVolumesResponseSchema);

  return volumes.failed ? [] : (volumes.data?.volumes ?? []);
}
