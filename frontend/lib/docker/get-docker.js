import { read } from "@/lib/api/read";
import {
  dockerLimitsResponseSchema,
  dockerNetworksResponseSchema,
  dockerVolumesResponseSchema,
  registriesResponseSchema,
} from "@/lib/schemas/docker";

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

export async function getRegistries() {
  const registries = await read(
    "/integrations/registries",
    registriesResponseSchema,
  );

  return registries.failed ? [] : (registries.data?.registries ?? []);
}

export async function getDockerNetworks() {
  const networks = await read("/docker/networks", dockerNetworksResponseSchema);

  return networks.failed ? [] : (networks.data?.networks ?? []);
}

export async function getDockerVolumes() {
  const volumes = await read("/docker/volumes", dockerVolumesResponseSchema);

  return volumes.failed ? [] : (volumes.data?.volumes ?? []);
}

export async function getDockerLimits() {
  const limits = await read("/docker/limits", dockerLimitsResponseSchema);

  return limits.failed
    ? { cpus: null, defaultMemoryLimit: null }
    : {
        cpus: limits.data?.limits?.cpus ?? null,
        defaultMemoryLimit: limits.data?.limits?.default_memory_limit ?? null,
      };
}
