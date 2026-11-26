import { api } from "@/lib/api/client";

export function createDockerNetwork(name) {
  return api.post("/docker/networks", { name });
}

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

export function createRegistry(payload) {
  return api.post("/integrations/registries", payload);
}

// Leave token out to keep the saved one; an empty string would wipe it.
export function updateRegistry(id, payload) {
  return api.patch(`/integrations/registries/${id}`, payload);
}

export function deleteRegistry(id) {
  return api.delete(`/integrations/registries/${id}`);
}

export function testRegistry(id) {
  return api.post(`/integrations/registries/${id}/test`);
}

export function pullContainerImage(id) {
  return api.post(`/applications/${id}/container/pull`);
}

export function saveContainerCompose(id, compose) {
  return api.put(`/applications/${id}/container/compose`, { compose });
}

export function updateContainerSettings(id, payload) {
  return api.put(`/applications/${id}/container`, payload);
}

export function getContainerSecrets(id) {
  return api.get(`/applications/${id}/container/secrets`);
}

export function acknowledgeContainerSecrets(id) {
  return api.post(`/applications/${id}/container/secrets/acknowledge`);
}

export function getContainerAccess(id) {
  return api.get(`/applications/${id}/container/access`);
}

export function enableContainerAccess(id, payload = {}) {
  return api.post(`/applications/${id}/container/access`, payload);
}

export function disableContainerAccess(id) {
  return api.delete(`/applications/${id}/container/access`);
}

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
