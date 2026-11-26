import { api } from "@/lib/api/client";

export function createDockerNetwork(name) {
  return api.post("/docker/networks", { name });
}

// The networks as they are now, to tell the user whether a move removed the old one.
export function listDockerNetworks() {
  return api.get("/docker/networks").then((response) => response.data?.networks ?? []);
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

// Registry credentials live under `/integrations` and are addressed by id.
export function createRegistry(payload) {
  return api.post("/integrations/registries", payload);
}

// Partial update: omit `token` to keep the stored one (an empty value would wipe it).
export function updateRegistry(id, payload) {
  return api.patch(`/integrations/registries/${id}`, payload);
}

export function deleteRegistry(id) {
  return api.delete(`/integrations/registries/${id}`);
}

// Resolves for a refused credential too; the verdict is in the body, not the status.
export function testRegistry(id) {
  return api.post(`/integrations/registries/${id}/test`);
}

// Pull a newer image and recreate the container (`up` alone reuses the image it has).
export function pullContainerImage(id) {
  return api.post(`/applications/${id}/container/pull`);
}

// Replace the compose file and restart on it. A 422 means the old file is still running.
export function saveContainerCompose(id, compose) {
  return api.put(`/applications/${id}/container/compose`, { compose });
}

// A container site's structured fields; rewrites the compose file and recreates the container.
export function updateContainerSettings(id, payload) {
  return api.put(`/applications/${id}/container`, payload);
}

// A one-click app's generated credentials, fetched only on request; the server records each read.
export function getContainerSecrets(id) {
  return api.get(`/applications/${id}/container/secrets`);
}

// "I have saved these": dismisses the first-run card. Separate from reading, since the values can't be rotated.
export function acknowledgeContainerSecrets(id) {
  return api.post(`/applications/${id}/container/secrets/acknowledge`);
}

/**
 * The app's own system user and the lines to copy for the Command-line access
 * card (RC-23, RC-28). A read, gated like the compose file.
 */
export function getContainerAccess(id) {
  return api.get(`/applications/${id}/container/access`);
}

/**
 * Turns container login on for the app's owner: its SSH login then opens the
 * container (RC-28). `ssh_key`, `password` and `confirm_container_only` are
 * optional; the first two go through the System Users rules.
 */
export function enableContainerAccess(id, payload = {}) {
  return api.post(`/applications/${id}/container/access`, payload);
}

// The owner's SSH goes back to a normal host login if it is still on.
export function disableContainerAccess(id) {
  return api.delete(`/applications/${id}/container/access`);
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
