import { api } from "@/lib/api/client";

/**
 * Start a clone. Throttled 5/min.
 *
 * Answers **202 with a Clone record**, not the finished site: the copying runs
 * on the queue now, so the request returns in milliseconds and the browser can
 * no longer time out while files are still being rsynced. Poll `fetchClone`
 * for progress.
 *
 * `name` is optional — omitted, the backend names the copy "{source} (Clone)".
 */
export function createClone(applicationId, payload) {
  return api.post(`/applications/${applicationId}/clone`, payload);
}

/**
 * Poll one clone while it runs. Throttled 120/min — generous on purpose, this
 * endpoint exists to be polled.
 */
export function fetchClone(cloneId) {
  return api.get(`/clones/${cloneId}`);
}
