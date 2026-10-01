import { api } from "@/lib/api/client";

// PUT /applications/{id}/webhook — { enabled, provider?, secret?, rotate? }.
// Git apps only (422 otherwise). Disabling keeps the URL and secret so
// switching it back on doesn't invalidate what the user pasted at the provider.
export function updateWebhook(id, payload) {
  return api.put(`/applications/${id}/webhook`, payload);
}

// Poll target after a deploy (status goes "provisioning" → "active"); the whole
// resource also refreshes last_commit / last_deployed_at / failed_step.
export function readApplication(id) {
  return api.get(`/applications/${id}`);
}

/**
 * The newest deploy only, for polling while the Deployment screen is open, so a
 * deploy started by a push shows up without a reload. `{ latest: row | null }`.
 */
export function fetchLatestDeployment(id) {
  return api.get(`/applications/${id}/deployments/latest`);
}

/** The history and the settings, in the one call that returns both. */
export function fetchDeployments(id) {
  return api.get(`/applications/${id}/deployments`);
}

/** Start a deploy. Answers 202 with the queued row. */
export function startDeployment(id) {
  return api.post(`/applications/${id}/deployments`);
}

/** One deployment, with the build output the list deliberately omits. */
export function fetchDeployment(id, deploymentId) {
  return api.get(`/applications/${id}/deployments/${deploymentId}`);
}

/** Re-run a deploy: same branch, current tip, same script. */
export function redeployDeployment(id, deploymentId) {
  return api.post(`/applications/${id}/deployments/${deploymentId}/redeploy`);
}

/**
 * Branch, deploy script and auto-deploy. Send the toggle as `webhook_enabled`:
 * the response calls it `auto_deploy`, but the request silently drops that name.
 */
export function updateDeploySettings(id, payload) {
  return api.put(`/applications/${id}/deployment-settings`, payload);
}
