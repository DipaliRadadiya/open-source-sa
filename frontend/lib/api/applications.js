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

// Needs `app_deployment,manage`, not the server-level `application` permission.
// Git sites only; anything else 404s.
export function deployApplication(id) {
  return api.post(`/applications/${id}/deploy`);
}

// action: start | stop | restart. Only meaningful when `has_process`.
export function controlApplicationProcess(id, action) {
  return api.post(`/applications/${id}/process/${action}`);
}

/**
 * Measure this site on disk now and store the result. Nothing else measures a
 * site that has not been touched through the panel. Cost scales with file
 * count, so the API throttles it to 10/min.
 */
export function measureApplicationSize(id) {
  return api.post(`/applications/${id}/directory-size`);
}

/**
 * Detect what is installed in the site's directory and record the verdict.
 * Returns `{ site_type_detection: {...} }`, not a full application. Throttled
 * 10/min, so disable the button while in flight. Git sites are not probed.
 */
export function detectApplicationSiteType(id) {
  return api.post(`/applications/${id}/detect-type`);
}

/**
 * Relabel a site's type. Changes which screens the panel offers, not what is
 * on disk. Returns the full `{ application }`; callers re-read the route since
 * sidebar items change. Refusals carry a sentence worth showing verbatim; the
 * backend re-probes the disk at apply time.
 */
export function changeApplicationSiteType(id, siteType) {
  return api.put(`/applications/${id}/site-type`, { site_type: siteType });
}

/**
 * Point the site at a holding page without touching files, databases, backups,
 * cron jobs or certificates. Throttled 10/min (each call reloads the web server).
 * Both this and enable 422 when already in the requested state (e.g. changed in
 * another tab); callers show the API's sentence and re-read.
 */
export function disableApplication(id) {
  return api.post(`/applications/${id}/disable`);
}

export function enableApplication(id) {
  return api.post(`/applications/${id}/enable`);
}

export function retryProvisioning(id) {
  return api.post(`/applications/${id}/provision`);
}

// Files are kept unless `remove_files` is sent; removing them is always the user's choice.
export function deleteApplication(id, { removeFiles = false, removeDatabases = false } = {}) {
  // Flags are omitted when false. No database ids: the API resolves the site's
  // databases itself at delete time.
  const params = {};
  if (removeFiles) params.remove_files = true;
  if (removeDatabases) params.remove_databases = true;

  return api.delete(`/applications/${id}`, {
    params: Object.keys(params).length ? params : undefined,
  });
}

/** The runtime settings a process-backed site runs with. */
export function updateApplicationRuntime(id, { start_command, app_port }) {
  return api.put(`/applications/${id}`, { start_command, app_port });
}

export function checkApplicationPort(port) {
  return api.get("/applications/port-check", { params: { port } });
}

// One call covers enable, credential change and disable; `enabled: false`
// ignores username/password. The API always takes both credentials together.
export function updateApplicationSecurity(id, payload) {
  return api.put(`/applications/${id}/security`, payload);
}

/**
 * One site's PHP settings. Every write tests and reloads FPM, affecting every
 * PHP site on the server, so the backend throttles it at 10/min and the UI
 * saves explicitly.
 */
export function updateApplicationPhp(id, payload) {
  return api.put(`/applications/${id}/php`, payload);
}

/**
 * Drop this site's own value for the given directives so they inherit again.
 * Partial payload on purpose: the controller only `fill()`s what it is sent.
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

// There is no un-isolate (the DELETE route returns 405): on the shared pool one
// compromised site could read every other site's .env.

/**
 * The bot policy and this site's exceptions, saved together. `policy` (one of
 * the GET /ai-bot-policies keys) is required; each list is replaced when sent
 * and left alone when omitted. The backend resolves them against each other.
 */
export function updateApplicationBotBlocker(id, { policy, blocked, allowed }) {
  return api.put(`/applications/${id}/bot-blocker`, { policy, blocked, allowed });
}

// One atomic save for the whole firewall screen. `categories: []` means ALL six
// on the backend, so never send an empty array to mean "none".
export function updateApplicationWaf(id, payload) {
  return api.put(`/applications/${id}/waf`, payload);
}

/**
 * Per-site fail2ban jail watching this site's access log. Jail and filter must
 * be sent together. The save runs `fail2ban-client` as a config test and
 * answers 500 with `{testOk: false, output}` when it fails, so read the body.
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
 * WordPress staging. Create and push are synchronous and slow (300s timeout)
 * with no job to poll, so the caller holds a pending state. A staging site is
 * deleted like any other application.
 */
export function createApplicationStaging(id, domain) {
  return api.post(`/applications/${id}/staging`, { domain });
}

export function pushApplicationStaging(id, mode) {
  return api.post(`/applications/${id}/staging/push`, { mode });
}

/**
 * Change the directory the web server serves. Rewrites the vhost and reloads,
 * so a wrong value takes the site down until corrected.
 */
export function updateWebRoot(id, webRoot) {
  return api.put(`/applications/${id}/web-root`, { web_root: webRoot });
}

/**
 * Hand the site folder to root and lock it. 422 with a translated message when
 * the folder is not safe to lock. Throttled to 10/min.
 */
export function lockApplicationRoot(id) {
  return api.post(`/applications/${id}/root-lock`);
}

// Only the status word is read, to notice a deploy or first setup ending.
export async function getApplicationStatus(id) {
  const res = await api.get(`/applications/${id}`);
  return res.data?.application?.status ?? null;
}
