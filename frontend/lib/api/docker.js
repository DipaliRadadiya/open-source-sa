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

/**
 * The credentials the panel generated for a one-click container app.
 *
 * Its own request, made only when somebody asks to see them — they are not on the
 * application payload precisely so they are not fetched, cached and re-rendered on
 * every visit to the page. The server records each read.
 */
export function getContainerSecrets(id) {
  return api.get(`/applications/${id}/container/secrets`);
}
