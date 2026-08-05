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
