import { api } from "@/lib/api/client";

export function createApplication(payload) {
  return api.post("/applications", payload);
}

export function getRepositories(accountId, params = {}) {
  return api.get(`/integrations/git/accounts/${accountId}/repositories`, { params });
}

export function getBranches(accountId, repository) {
  return api.get(`/integrations/git/accounts/${accountId}/branches`, {
    params: { repository },
  });
}

// Takes `app_deployment,manage` — the Deployment screen's permission, not the
// server-level `application` one. Git sites only; anything else 404s.
export function deployApplication(id) {
  return api.post(`/applications/${id}/deploy`);
}

// action: start | stop | restart. Only meaningful when `has_process`.
export function controlApplicationProcess(id, action) {
  return api.post(`/applications/${id}/process/${action}`);
}

export function retryProvisioning(id) {
  return api.post(`/applications/${id}/provision`);
}

// Files are kept unless `remove_files` is sent — deleting the panel record must
// not silently destroy someone's code, so the flag is always the user's choice.
export function deleteApplication(id, { removeFiles = false } = {}) {
  return api.delete(`/applications/${id}`, {
    params: removeFiles ? { remove_files: true } : undefined,
  });
}

export function checkApplicationPort(port) {
  return api.get("/applications/port-check", { params: { port } });
}

// One call does enable, credential change, AND disable — `enabled: false`
// ignores username/password entirely. There is no separate "just change the
// password" call: the API always takes both together.
export function updateApplicationSecurity(id, payload) {
  return api.put(`/applications/${id}/security`, payload);
}

// `policy` is one of allow_all | block_training | block_all — the keys of
// GET /ai-bot-policies, never a locally invented list.
export function updateApplicationBotBlocker(id, policy) {
  return api.put(`/applications/${id}/bot-blocker`, { policy });
}

// One atomic save for the whole firewall screen — toggle, mode, categories and
// both rule lists go together. Note `categories: []` means ALL SIX on the
// backend, not none, so callers must never send an empty array to mean "check
// nothing"; turning the firewall off is what expresses that.
export function updateApplicationWaf(id, payload) {
  return api.put(`/applications/${id}/waf`, payload);
}
