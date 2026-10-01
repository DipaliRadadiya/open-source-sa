import { api } from "@/lib/api/client";

const BASE = "/integrations/storage/destinations";

// Read from the browser so the backup form can refresh after a destination is added elsewhere.
export function listDestinations() {
  return api.get(BASE);
}

/** `{name, endpoint?, region?, bucket, prefix?, access_key, secret_key}`. */
export function createDestination(payload) {
  return api.post(BASE, payload);
}

// IMPORTANT: the presence of `access_key`/`secret_key` means "rotate these"; omit them to keep the stored ones.
export function updateDestination(id, payload) {
  return api.patch(`${BASE}/${id}`, payload);
}

/** Makes a real call to the destination. The result is not persisted. */
export function testDestination(id) {
  return api.post(`${BASE}/${id}/test`);
}

// The backend does not check for backup targets still pointing at this destination.
export function deleteDestination(id) {
  return api.delete(`${BASE}/${id}`);
}

// A URL rather than a 302, which fetch would follow and fail to parse.
export function startDriveConnect(id) {
  return api.post(`${BASE}/${id}/oauth/start`);
}

// Google's redirect carries no auth token, so the callback page forwards the code. No id: it is sealed in `state`.
export function completeDriveConnect({ code, state }) {
  return api.post("/integrations/storage/oauth/callback", { code, state });
}
