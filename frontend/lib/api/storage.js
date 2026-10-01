import { api } from "@/lib/api/client";

const BASE = "/integrations/storage/destinations";

/**
 * The destinations, read from the browser. Lets the backup form refresh its
 * list after a destination is added in another tab, without losing input.
 */
export function listDestinations() {
  return api.get(BASE);
}

/** `{name, endpoint?, region?, bucket, prefix?, access_key, secret_key}`. */
export function createDestination(payload) {
  return api.post(BASE, payload);
}

/**
 * Partial update.
 *
 * IMPORTANT: the backend treats the *presence* of `access_key`/`secret_key` as
 * "rotate these"; omit them to keep the stored credentials.
 */
export function updateDestination(id, payload) {
  return api.patch(`${BASE}/${id}`, payload);
}

/** Makes a real call to the destination. The result is not persisted. */
export function testDestination(id) {
  return api.post(`${BASE}/${id}/test`);
}

/**
 * Removes the panel's record. The backend does not check for backup targets
 * still pointing at this destination.
 */
export function deleteDestination(id) {
  return api.delete(`${BASE}/${id}`);
}

/**
 * Returns the Google approval URL and the redirect URI the client must have
 * registered. A URL rather than a 302, which fetch would follow and fail to parse.
 */
export function startDriveConnect(id) {
  return api.post(`${BASE}/${id}/oauth/start`);
}

/**
 * Forwards Google's OAuth code to the API from the callback page (Google's
 * redirect carries no auth token). The client secret never reaches the browser.
 * No destination id: it is sealed inside `state`.
 */
export function completeDriveConnect({ code, state }) {
  return api.post("/integrations/storage/oauth/callback", { code, state });
}
