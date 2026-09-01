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

/**
 * Measure this site on disk, now, and store the result.
 *
 * Nothing else computes it from scratch: file operations queue a re-measure
 * about a minute later, and there is no schedule — so a site nobody has touched
 * through the panel has never been measured at all. This is the only way to ask.
 *
 * `du` walks every inode, so the cost is the site's file count rather than its
 * size. The API throttles it to 10/min for that reason; the caller shows the
 * wait rather than pretending it is instant.
 */
export function measureApplicationSize(id) {
  return api.post(`/applications/${id}/directory-size`);
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

/**
 * The runtime settings a process-backed site actually runs with.
 *
 * The API has taken these on `PUT /applications/{id}` all along; only the
 * create form ever offered them, so a site started with the wrong entry file
 * could not be corrected — the site had to be deleted and made again.
 */
export function updateApplicationRuntime(id, { start_command, app_port }) {
  return api.put(`/applications/${id}`, { start_command, app_port });
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

/**
 * Drop this site's own value for one or more directives, so they inherit again.
 *
 * A partial payload on purpose: every rule is `sometimes`, and the controller
 * `fill()`s only what it was sent, so nulling one field cannot disturb a value
 * the user is midway through editing elsewhere on the form.
 */
export function resetApplicationPhpFields(id, names) {
  return api.put(
    `/applications/${id}/php`,
    Object.fromEntries(names.map((name) => [name, null])),
  );
}

/** Move this site onto its own FPM pool, running as its own user. */
export function isolateApplicationPhp(id) {
  return api.post(`/applications/${id}/php/isolate`);
}

// There is no un-isolate. `DELETE /applications/{id}/php/isolate` was removed
// (405 since backend 9ff978c): on the shared pool a site runs as the web
// server's own account, so one compromised site can read every other site's
// .env. It is not a mode anyone gets to choose any more.

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

/**
 * Change the directory the web server serves.
 *
 * Creates it if missing, rewrites the vhost, config-tests and reloads — so a
 * wrong value takes the site down until it is corrected, which is why the
 * dialog says so before saving.
 */
export function updateWebRoot(id, webRoot) {
  return api.put(`/applications/${id}/web-root`, { web_root: webRoot });
}
