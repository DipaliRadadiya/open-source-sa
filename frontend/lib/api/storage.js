import { api } from "@/lib/api/client";

const BASE = "/integrations/storage/destinations";

/**
 * The destinations, read from the browser.
 *
 * Every screen gets this list from its server component, so for a long time
 * there was no client-side read at all. The backup form needs one: its
 * "Add destination" button opens the storage page in a new tab, and without a
 * way to ask again the only route back was reloading the page — which threw
 * away everything already typed into the form.
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

/**
 * Asks where to send the operator to approve, and which redirect URI their
 * Google client has to have registered for the round trip to work.
 *
 * Returns the URL rather than redirecting: a 302 here would be followed by the
 * fetch layer, which would then try to parse Google's sign-in page as JSON.
 */
export function startDriveConnect(id) {
  return api.post(`${BASE}/${id}/oauth/start`);
}

/**
 * Hands Google's answer back to the panel, authenticated.
 *
 * This is the reason the callback is a page and not an API route. Google
 * redirects a *browser*, which arrives carrying no token — so the page is the
 * only participant that can turn that redirect into a request the API will
 * accept. It forwards the code to be spent server-side; the client secret
 * never exists in the browser.
 *
 * No destination id: which destination this was for is sealed inside `state`
 * and read there. Sending one would make the seal decorative.
 */
export function completeDriveConnect({ code, state }) {
  return api.post("/integrations/storage/oauth/callback", { code, state });
}
