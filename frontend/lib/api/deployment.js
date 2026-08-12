import { api } from "@/lib/api/client";

// PUT /applications/{id}/webhook — { enabled, provider?, secret?, rotate? }.
// Git apps only (422 otherwise). Disabling keeps the URL and secret so
// switching it back on doesn't invalidate what the user pasted at the provider.
export function updateWebhook(id, payload) {
  return api.put(`/applications/${id}/webhook`, payload);
}

// Poll target after a deploy: a deploy flips status to "provisioning" with
// steps[] filling, then back to "active". Re-reading the whole resource keeps
// last_commit / last_deployed_at / failed_step in sync too.
export function readApplication(id) {
  return api.get(`/applications/${id}`);
}

/** The history and the settings, in the one call that returns both. */
export function fetchDeployments(id) {
  return api.get(`/applications/${id}/deployments`);
}

/**
 * Start a deploy. Answers 202 with the row, so the screen can show it as queued
 * straight away rather than waiting to be told it exists.
 */
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
 * Branch, deploy script and auto-deploy.
 *
 * The toggle goes as `webhook_enabled`. The response calls the same fact
 * `auto_deploy`, and the API reference's request example uses that name — but
 * the FormRequest accepts only `webhook_enabled`, so `auto_deploy` would be
 * dropped without an error.
 */
export function updateDeploySettings(id, payload) {
  return api.put(`/applications/${id}/deployment-settings`, payload);
}
