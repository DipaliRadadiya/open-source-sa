import { api } from "@/lib/api/client";

const BASE = "/integrations/storage/destinations";

/** `{name, endpoint?, region?, bucket, prefix?, access_key, secret_key}`. */
export function createDestination(payload) {
  return api.post(BASE, payload);
}

/**
 * Partial update.
 *
 * IMPORTANT: the backend reads the *presence* of `access_key`/`secret_key` as
 * "rotate these". Omitting them keeps what is stored, which is why renaming
 * and rotating are two separate dialogs here rather than one form that posts
 * every field it knows about.
 */
export function updateDestination(id, payload) {
  return api.patch(`${BASE}/${id}`, payload);
}

/**
 * Makes a real call out to the destination. Nothing about the result is
 * persisted — the resource has no `verified_at` — so the answer is only ever
 * as fresh as the moment it was asked.
 */
export function testDestination(id) {
  return api.post(`${BASE}/${id}/test`);
}

/**
 * Removes the panel's record. Note there is no dependency guard on the
 * backend: a backup target pointing at this destination is not checked for.
 */
export function deleteDestination(id) {
  return api.delete(`${BASE}/${id}`);
}
