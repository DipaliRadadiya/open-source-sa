import { api } from "@/lib/api/client";
import { containerRedeploySchema, processSchema } from "@/lib/schemas/application";

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

export function deployApplication(id) {
  return api.post(`/applications/${id}/deploy`);
}

export function controlApplicationProcess(id, action) {
  return api.post(`/applications/${id}/process/${action}`);
}

export function convertApplicationSupervisor(id) {
  return api.post(`/applications/${id}/supervisor/convert`);
}

export function measureApplicationSize(id) {
  return api.post(`/applications/${id}/directory-size`);
}

export function detectApplicationSiteType(id) {
  return api.post(`/applications/${id}/detect-type`);
}

export function changeApplicationSiteType(id, siteType) {
  return api.put(`/applications/${id}/site-type`, { site_type: siteType });
}

export function disableApplication(id) {
  return api.post(`/applications/${id}/disable`);
}

export function enableApplication(id) {
  return api.post(`/applications/${id}/enable`);
}

export function retryProvisioning(id) {
  return api.post(`/applications/${id}/provision`);
}

export function deleteApplication(id, { removeFiles = false, removeDatabases = false, removeDockerResources = false, removeDockerImages = null, removeSystemUser = false } = {}) {
  const params = {};
  if (removeFiles) params.remove_files = true;
  if (removeDatabases) params.remove_databases = true;
  if (removeDockerResources) params.remove_docker_resources = true;
  if (removeDockerImages !== null) params.remove_docker_images = removeDockerImages;
  if (removeSystemUser) params.remove_system_user = true;

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

export function switchApplicationCronjobsPhp(id, { version, cronjobIds }) {
  return api.post(`/applications/${id}/cronjobs/switch-php`, { version, cronjob_ids: cronjobIds });
}

export function updateApplicationSecurity(id, payload) {
  return api.put(`/applications/${id}/security`, payload);
}

export function updateApplicationPhp(id, payload) {
  return api.put(`/applications/${id}/php`, payload);
}

export function resetApplicationPhpFields(id, names) {
  return api.put(
    `/applications/${id}/php`,
    Object.fromEntries(names.map((name) => [name, null])),
  );
}

export function isolateApplicationPhp(id) {
  return api.post(`/applications/${id}/php/isolate`);
}

export function updateApplicationBotBlocker(id, { policy, blocked, allowed }) {
  return api.put(`/applications/${id}/bot-blocker`, { policy, blocked, allowed });
}

// An empty categories array means ALL categories on the backend.
export function updateApplicationWaf(id, payload) {
  return api.put(`/applications/${id}/waf`, payload);
}

export function saveApplicationFail2ban(id, { jail, filter }, { safetyCopy = true } = {}) {
  return api.post(`/applications/${id}/fail2ban`, {
    jail_config_content: jail,
    filter_config_content: filter,
    safety_copy: safetyCopy,
  });
}

export function deleteApplicationFail2ban(id, { safetyCopy = true } = {}) {
  return api.delete(`/applications/${id}/fail2ban`, { data: { safety_copy: safetyCopy } });
}

export function createApplicationStaging(id, domain) {
  return api.post(`/applications/${id}/staging`, { domain });
}

export function pushApplicationStaging(id, mode, { backup = false, safetyCopy = true } = {}) {
  return api.post(`/applications/${id}/staging/push`, { mode, safety_copy: safetyCopy, ...(backup ? { backup: true } : {}) });
}

export function pullApplicationStaging(id, mode, { safetyCopy = true } = {}) {
  return api.post(`/applications/${id}/staging/pull`, { mode, safety_copy: safetyCopy });
}

export function restoreStagingSafetyCopy(id, name) {
  return api.post(`/applications/${id}/staging/safety-copies/${encodeURIComponent(name)}/restore`);
}

export function updateWebRoot(id, webRoot) {
  return api.put(`/applications/${id}/web-root`, { web_root: webRoot });
}

export function lockApplicationRoot(id) {
  return api.post(`/applications/${id}/root-lock`);
}

export async function getApplicationStatus(id) {
  const res = await api.get(`/applications/${id}`);
  return res.data?.application?.status ?? null;
}

export async function getContainerRedeploy(id) {
  const res = await api.get(`/applications/${id}`);
  const parsed = containerRedeploySchema.nullish().safeParse(res.data?.application?.container_redeploy);
  return parsed.success ? (parsed.data ?? null) : null;
}

export async function getApplicationProcess(id, { signal } = {}) {
  const res = await api.get(`/applications/${id}`, { signal });
  const parsed = processSchema.nullish().safeParse(res.data?.application?.process);
  return parsed.success ? (parsed.data ?? null) : null;
}

export function updateNodeVersion(id, nodeVersion) {
  return api.put(`/applications/${id}/node-version`, { node_version: nodeVersion });
}

export function banApplicationIp(id, ip) {
  return api.post(`/applications/${id}/fail2ban/bans`, { ip });
}

export function unbanApplicationIp(id, ip) {
  return api.delete(`/applications/${id}/fail2ban/bans/${encodeURIComponent(ip)}`);
}

export function searchApplications(search, { signal } = {}) {
  return api.get("/applications", { params: { per_page: 20, sort: "name", ...(search ? { search } : {}) }, signal });
}

export async function getApplicationNavCatalog(id) {
  const res = await api.get("/permissions", { params: { level: "application", application_id: id } });
  return Array.isArray(res.data?.permissions) ? res.data.permissions : [];
}
