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

// Nothing else measures a site untouched by the panel. Throttled 10/min (cost scales with file count).
export function measureApplicationSize(id) {
  return api.post(`/applications/${id}/directory-size`);
}

// Returns `{ site_type_detection }`, not an application. Throttled 10/min; git sites are not probed.
export function detectApplicationSiteType(id) {
  return api.post(`/applications/${id}/detect-type`);
}

// Changes which screens the panel offers, not what is on disk. Refusals are worth showing verbatim.
export function changeApplicationSiteType(id, siteType) {
  return api.put(`/applications/${id}/site-type`, { site_type: siteType });
}

// Holding page; nothing else is touched. Throttled 10/min (reloads the web server).
// This and enable 422 when already in that state; show the API's sentence and re-read.
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
  // Flags are omitted when false; the API resolves the site's databases itself.
  const params = {};
  if (removeFiles) params.remove_files = true;
  if (removeDatabases) params.remove_databases = true;

  return api.delete(`/applications/${id}`, {
    params: Object.keys(params).length ? params : undefined,
  });
}

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

// Every write tests and reloads FPM for every PHP site: throttled 10/min, and the UI saves explicitly.
export function updateApplicationPhp(id, payload) {
  return api.put(`/applications/${id}/php`, payload);
}

// Partial payload on purpose: the controller only `fill()`s what it is sent.
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

// `policy` is required; each list is replaced when sent and left alone when omitted.
export function updateApplicationBotBlocker(id, { policy, blocked, allowed }) {
  return api.put(`/applications/${id}/bot-blocker`, { policy, blocked, allowed });
}

// One atomic save for the whole firewall screen. `categories: []` means ALL six
// on the backend, so never send an empty array to mean "none".
export function updateApplicationWaf(id, payload) {
  return api.put(`/applications/${id}/waf`, payload);
}

// Jail and filter go together. A failed config test answers 500 `{testOk: false, output}`: read the body.
export function saveApplicationFail2ban(id, { jail, filter }) {
  return api.post(`/applications/${id}/fail2ban`, {
    jail_config_content: jail,
    filter_config_content: filter,
  });
}

export function deleteApplicationFail2ban(id) {
  return api.delete(`/applications/${id}/fail2ban`);
}

// Synchronous and slow (300s timeout) with no job to poll; the caller holds a pending state.
export function createApplicationStaging(id, domain) {
  return api.post(`/applications/${id}/staging`, { domain });
}

export function pushApplicationStaging(id, mode) {
  return api.post(`/applications/${id}/staging/push`, { mode });
}

// Rewrites the vhost and reloads: a wrong value takes the site down until corrected.
export function updateWebRoot(id, webRoot) {
  return api.put(`/applications/${id}/web-root`, { web_root: webRoot });
}

// 422 with a translated message when the folder is not safe to lock. Throttled 10/min.
export function lockApplicationRoot(id) {
  return api.post(`/applications/${id}/root-lock`);
}

// Only the status word is read, to notice a deploy or first setup ending.
export async function getApplicationStatus(id) {
  const res = await api.get(`/applications/${id}`);
  return res.data?.application?.status ?? null;
}
