import { api } from "@/lib/api/client";

/** Sets what bare `php` resolves to. Sites keep the version their pool runs. */
export function setDefaultPhpVersion(version) {
  return api.put("/php/default", { default: version });
}

/** Queued: returns 202 and the caller polls. Already installed returns 200. */
export function installPhpVersion(version) {
  return api.post("/php/versions", { version });
}

// `422` for the panel's own version, a version a site pins, and the current default.
export function removePhpVersion(version) {
  return api.delete(`/php/versions/${encodeURIComponent(version)}`);
}

// A vendor `.so` loaded as a `zend_extension`, not an apt package.
export function getIonCube(version, { signal } = {}) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/ioncube`, { signal });
}

// 202, then poll. `422` when ionCube has no loader for this version.
// `safetyCopy: false` keeps the replaced loader and ini files in memory instead of on disk.
export function installIonCube(version, { safetyCopy = true } = {}) {
  return api.post(`/php/versions/${encodeURIComponent(version)}/ioncube`, { safety_copy: safetyCopy });
}

/** Immediate, unlike the install: it deletes one ini file and reloads. */
export function removeIonCube(version, { safetyCopy = true } = {}) {
  return api.delete(`/php/versions/${encodeURIComponent(version)}/ioncube`, { data: { safety_copy: safetyCopy } });
}

export function getPhpExtensions(version, { signal } = {}) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/extensions`, { signal });
}

// On installs if needed (202, then poll); off unlinks. The package is never purged.
export function setPhpExtension(version, name, enabled) {
  return api.put(
    `/php/versions/${encodeURIComponent(version)}/extensions/${encodeURIComponent(name)}`,
    { enabled },
  );
}

export function readPhpIni(version) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/ini`);
}

// A bad ini can stop FPM; the backend tests with `php-fpm -t` and restores on failure.
// `safetyCopy: false` writes no php.ini.panel-bak; the old text is held in memory for the check.
export function savePhpIni(version, contents, { safetyCopy = true } = {}) {
  return api.put(`/php/versions/${encodeURIComponent(version)}/ini`, {
    contents,
    acknowledged: true,
    safety_copy: safetyCopy,
  });
}

// The common php.ini settings as fields (RC-8). PUT takes any subset; the backend
// rewrites only those lines and tests the file as the raw editor does.
export function readPhpSettings(version) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/settings`);
}

export function savePhpSettings(version, settings, { safetyCopy = true } = {}) {
  return api.put(`/php/versions/${encodeURIComponent(version)}/settings`, { ...settings, safety_copy: safetyCopy });
}
