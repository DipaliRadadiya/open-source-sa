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
 * Per-site fail2ban. A different feature from the server-level one: this jail
 * watches this site's own access log.
 *
 * Both halves go together because the backend rejects a partial submission —
 * a jail whose filter does not exist stops fail2ban reloading at all.
 *
 * The save is also the config test: the backend runs the pair through
 * `fail2ban-client` first and answers `{testOk: false, output}` when it does
 * not parse. It uses status 500 for that, so callers must read the body rather
 * than treating any failure as an outage.
 */
export function saveApplicationFail2ban(id, { jail, filter }) {
  return api.post(`/applications/${id}/fail2ban`, {
    jail_config_content: jail,
    filter_config_content: filter,
  });
}

export function deleteApplicationFail2ban(id) {
  return api.delete(`/applications/${id}/fail2ban`);
}

/**
 * Staging: a WordPress-only copy of the site to break safely.
 *
 * Both calls are synchronous on the backend and slow — create provisions a
 * site and rsyncs it, push takes production offline and rsyncs the other way
 * (300s timeout). There is no job to poll, so the caller has to hold a pending
 * state for the whole round trip rather than showing progress.
 *
 * There is no delete: the staging site is an application, removed like any
 * other one.
 */
export function createApplicationStaging(id, domain) {
  return api.post(`/applications/${id}/staging`, { domain });
}

export function pushApplicationStaging(id, mode) {
  return api.post(`/applications/${id}/staging/push`, { mode });
}
