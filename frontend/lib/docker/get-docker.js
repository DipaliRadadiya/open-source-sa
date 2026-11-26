import { read } from "@/lib/api/read";
import {
  dockerLimitsResponseSchema,
  dockerNetworksResponseSchema,
  dockerVolumesResponseSchema,
  registriesResponseSchema,
} from "@/lib/schemas/docker";

// Networks and volumes together. A failed read is a failure, not an empty list.
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

// The registries page: a failed read is a failure, not "no credentials stored".
export async function getRegistriesPage() {
  const result = await read(
    "/integrations/registries",
    registriesResponseSchema,
  );

  return {
    registries: result.data?.registries ?? [],
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
  };
}

// Just the registries, for a site's picker. Degrades to an empty list: the saved value still shows.
export async function getRegistries() {
  const registries = await read(
    "/integrations/registries",
    registriesResponseSchema,
  );

  return registries.failed ? [] : (registries.data?.registries ?? []);
}

// Just the networks, for a site's picker. Degrades to an empty list, like the registries.
export async function getDockerNetworks() {
  const networks = await read("/docker/networks", dockerNetworksResponseSchema);

  return networks.failed ? [] : (networks.data?.networks ?? []);
}

// Just the volumes, for a site's mount list. Degrades to an empty list.
export async function getDockerVolumes() {
  const volumes = await read("/docker/volumes", dockerVolumesResponseSchema);

  return volumes.failed ? [] : (volumes.data?.volumes ?? []);
}

// The server's CPU and memory, for those fields' hints. Nulls on failure, never a guessed number.
export async function getDockerLimits() {
  const limits = await read("/docker/limits", dockerLimitsResponseSchema);

  return limits.failed
    ? { cpus: null, defaultMemoryLimit: null }
    : {
        cpus: limits.data?.limits?.cpus ?? null,
        defaultMemoryLimit: limits.data?.limits?.default_memory_limit ?? null,
      };
}
