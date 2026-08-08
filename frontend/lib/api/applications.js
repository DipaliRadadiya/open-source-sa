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
/**
 * One site's PHP settings.
 *
 * Every write tests the FPM configuration and reloads the daemon, and a reload
 * touches every PHP site on this server — which is why the backend throttles
 * this at 10/min and why the UI saves once, deliberately, rather than on every
 * keystroke.
 */
export function updateApplicationPhp(id, payload) {
  return api.put(`/applications/${id}/php`, payload);
}

/** Move this site onto its own FPM pool, running as its own user. */
export function isolateApplicationPhp(id) {
  return api.post(`/applications/${id}/php/isolate`);
}

/** Put it back on the shared pool — the way out if isolation broke something. */
export function unisolateApplicationPhp(id) {
  return api.delete(`/applications/${id}/php/isolate`);
}

/**
 * The policy and this site's own exceptions, in one request.
 *
 * `policy` is always required. Both lists are replaced wholesale when sent and
 * left alone when omitted, so they go together with the policy rather than
 * through a second save — the backend resolves them against each other (an
 * allow beats a block of the same bot), and saving half of that would enforce
 * a rule nobody asked for.
 */
export function updateApplicationBotBlocker(id, { policy, blocked, allowed }) {
  return api.put(`/applications/${id}/bot-blocker`, { policy, blocked, allowed });
}

// One atomic save for the whole firewall screen — toggle, mode, categories and
// both rule lists go together. Note `categories: []` means ALL SIX on the
// backend, not none, so callers must never send an empty array to mean "check
// nothing"; turning the firewall off is what expresses that.
export function updateApplicationWaf(id, payload) {
  return api.put(`/applications/${id}/waf`, payload);
}

/**
 * Per-site fail2ban. A different feature from the server-level one: these
 * jails watch this site's own access log.
 *
 * `ban` reaches only the generic jail (the backend picks `jailNames()[0]`),
 * while `unban` releases the address from every jail this site has — so
 * banning is site-wide by nature and unbanning is honest as one button.
 */
export function updateApplicationFail2ban(id, enabled) {
  return api.put(`/applications/${id}/fail2ban`, { enabled });
}

export function banApplicationIp(id, ip) {
  return api.post(`/applications/${id}/fail2ban/ban`, { ip });
}

export function unbanApplicationIp(id, ip) {
  return api.delete(`/applications/${id}/fail2ban/ban/${encodeURIComponent(ip)}`);
}
