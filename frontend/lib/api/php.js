import { api } from "@/lib/api/client";
import { RETRY_RESTART } from "@/lib/api/restart-blip";

export function setDefaultPhpVersion(version) {
  return api.put("/php/default", { default: version });
}

export function installPhpVersion(version) {
  return api.post("/php/versions", { version }, RETRY_RESTART);
}

export function removePhpVersion(version) {
  return api.delete(`/php/versions/${encodeURIComponent(version)}`);
}

export function getIonCube(version, { signal } = {}) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/ioncube`, { signal });
}

export function installIonCube(version, { safetyCopy = true } = {}) {
  return api.post(`/php/versions/${encodeURIComponent(version)}/ioncube`, { safety_copy: safetyCopy });
}

export function removeIonCube(version, { safetyCopy = true } = {}) {
  return api.delete(`/php/versions/${encodeURIComponent(version)}/ioncube`, { data: { safety_copy: safetyCopy } });
}

export function getPhpExtensions(version, { signal } = {}) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/extensions`, { signal });
}

export function setPhpExtension(version, name, enabled) {
  return api.put(
    `/php/versions/${encodeURIComponent(version)}/extensions/${encodeURIComponent(name)}`,
    { enabled },
  );
}

export function readPhpIni(version) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/ini`);
}

export function savePhpIni(version, contents, { safetyCopy = true } = {}) {
  return api.put(`/php/versions/${encodeURIComponent(version)}/ini`, {
    contents,
    acknowledged: true,
    safety_copy: safetyCopy,
  });
}

export function readPhpSettings(version) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/settings`);
}

export function savePhpSettings(version, settings, { safetyCopy = true } = {}) {
  return api.put(`/php/versions/${encodeURIComponent(version)}/settings`, { ...settings, safety_copy: safetyCopy });
}
