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
 * Registry credentials. Filed under `/integrations`, with the git accounts and
 * storage destinations, because that is what they are: a credential held somewhere
 * else that the features consume. Hence also an id in the path, unlike the networks
 * and volumes above whose identifier is the name Docker knows them by.
 */
export function createRegistry(payload) {
  return api.post("/integrations/registries", payload);
}

/**
 * A partial update, and partial is the point: omit `token` and the stored one is
 * kept. The form renders an empty password box, so sending it as an empty value
 * would wipe a working credential every time somebody fixed a typo in the name.
 */
export function updateRegistry(id, payload) {
  return api.patch(`/integrations/registries/${id}`, payload);
}

export function deleteRegistry(id) {
  return api.delete(`/integrations/registries/${id}`);
}

/**
 * Ask the registry whether the stored credential works.
 *
 * Resolves for a refused credential as well as an accepted one — the request
 * succeeded and the panel learned something either way, so the verdict is in the
 * body rather than in the status code.
 */
export function testRegistry(id) {
  return api.post(`/integrations/registries/${id}/test`);
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
 * The compose file this site runs.
 *
 * A site created in Simple mode has none stored — the panel renders one from its
 * fields — so this answers with what that render produces and says so, because
 * saving it back is what takes the file over.
 */
export function getContainerCompose(id) {
  return api.get(`/applications/${id}/container/compose`);
}

/**
 * Replace it, and bring the site up on the new one.
 *
 * A 422 here means the site is still running the file it was running before: the
 * server rolls back rather than leaving it down holding the text that broke it.
 */
export function saveContainerCompose(id, compose) {
  return api.put(`/applications/${id}/container/compose`, { compose });
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

/**
 * "I have saved these."
 *
 * Separate from reading them on purpose: the panel cannot rotate a generated
 * credential — that means rewriting the compose file and the credential inside the
 * running database — so dismissing the first-run card as a side effect of showing it
 * would lose an unrecoverable password to a page refresh.
 */
export function acknowledgeContainerSecrets(id) {
  return api.post(`/applications/${id}/container/secrets/acknowledge`);
}

/**
 * Image discovery (DS-02). All three read the registry without pulling, so
 * they are safe to call while the user types; `signal` lets a newer keystroke
 * abandon an older request.
 */
export function searchDockerImages(q, { limit = 10, signal } = {}) {
  return api.get("/docker/images/search", { params: { q, limit }, signal });
}

export function getDockerImageTags(image, { limit = 20, signal } = {}) {
  return api.get("/docker/images/tags", { params: { image, limit }, signal });
}

export function inspectDockerImage(image, { registryId, signal } = {}) {
  return api.get("/docker/images/inspect", {
    params: { image, ...(registryId ? { registry_id: registryId } : {}) },
    signal,
  });
}
