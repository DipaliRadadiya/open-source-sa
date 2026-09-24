import { api } from "@/lib/api/client";

export function createDockerNetwork(name) {
  return api.post("/docker/networks", { name });
}

export function deleteDockerNetwork(name) {
  return api.delete(`/docker/networks/${encodeURIComponent(name)}`);
}

export function createDockerVolume(name) {
  return api.post("/docker/volumes", { name });
}

export function deleteDockerVolume(name) {
  return api.delete(`/docker/volumes/${encodeURIComponent(name)}`);
}

/**
 * A container site's structured fields.
 *
 * Its own endpoint, not the generic application update: applying it rewrites
 * the compose file and recreates the container.
 */
export function updateContainerSettings(id, payload) {
  return api.put(`/applications/${id}/container`, payload);
}
