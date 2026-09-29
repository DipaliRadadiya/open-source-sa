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
 * Registry credentials, which are server-level rows rather than Docker objects —
 * hence an id in the path, unlike the networks and volumes above whose identifier
 * is the name Docker knows them by.
 */
export function createRegistry(payload) {
  return api.post("/docker/registries", payload);
}

/**
 * A partial update, and partial is the point: omit `token` and the stored one is
 * kept. The form renders an empty password box, so sending it as an empty value
 * would wipe a working credential every time somebody fixed a typo in the name.
 */
export function updateRegistry(id, payload) {
  return api.patch(`/docker/registries/${id}`, payload);
}

export function deleteRegistry(id) {
  return api.delete(`/docker/registries/${id}`);
}

/**
 * Ask the registry whether the stored credential works.
 *
 * Resolves for a refused credential as well as an accepted one — the request
 * succeeded and the panel learned something either way, so the verdict is in the
 * body rather than in the status code.
 */
export function testRegistry(id) {
  return api.post(`/docker/registries/${id}/test`);
}

/**
 * Fetch a newer image and recreate the container on it.
 *
 * Its own call rather than part of the settings save, because `up` alone reuses an
 * image it already has — a site on a floating tag would never move.
 */
export function pullContainerImage(id) {
  return api.post(`/applications/${id}/container/pull`);
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
