import { api } from "@/lib/api/client";

/**
 * Start a clone. Throttled 5/min. Answers **202 with a Clone record** (copying
 * runs on the queue); poll `fetchClone` for progress.
 *
 * `name` is optional; the backend defaults to "{source} (Clone)".
 */
export function createClone(applicationId, payload) {
  return api.post(`/applications/${applicationId}/clone`, payload);
}

/** Poll one clone while it runs. Throttled 120/min. */
export function fetchClone(cloneId) {
  return api.get(`/clones/${cloneId}`);
}
