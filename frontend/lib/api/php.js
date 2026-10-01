import { api } from "@/lib/api/client";

/** Sets what bare `php` resolves to. Sites keep the version their pool runs. */
export function setDefaultPhpVersion(version) {
  return api.put("/php/default", { default: version });
}

/** Queued: returns 202 and the caller polls. Already installed returns 200. */
export function installPhpVersion(version) {
  return api.post("/php/versions", { version });
}

/**
 * `422` for the three refusals: the panel's own version, a version a site pins
 * (the message names them), and the current default.
 */
export function removePhpVersion(version) {
  return api.delete(`/php/versions/${encodeURIComponent(version)}`);
}

/**
 * The ionCube Loader for one PHP version. Separate from the extensions catalog
 * because it is a vendor `.so` loaded as a `zend_extension`, not an apt package.
 */
export function getIonCube(version, { signal } = {}) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/ioncube`, { signal });
}

/**
 * Queued: returns 202 and the caller polls. `422` when ionCube has no loader
 * for this version (the UI checks `supported` first).
 */
export function installIonCube(version) {
  return api.post(`/php/versions/${encodeURIComponent(version)}/ioncube`);
}

/** Immediate, unlike the install: it deletes one ini file and reloads. */
export function removeIonCube(version) {
  return api.delete(`/php/versions/${encodeURIComponent(version)}/ioncube`);
}

export function getPhpExtensions(version, { signal } = {}) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/extensions`, { signal });
}

/**
 * One switch per extension: on installs it if needed (202, then poll), off
 * unlinks it. The package is never purged — re-enabling is instant.
 */
export function setPhpExtension(version, name, enabled) {
  return api.put(
    `/php/versions/${encodeURIComponent(version)}/extensions/${encodeURIComponent(name)}`,
    { enabled },
  );
}

export function readPhpIni(version) {
  return api.get(`/php/versions/${encodeURIComponent(version)}/ini`);
}

/**
 * Replace the ini. The API requires `acknowledged`, since a bad ini can stop
 * FPM. The backend tests with `php-fpm{version} -t` and restores the previous
 * file if PHP rejects it.
 */
export function savePhpIni(version, contents) {
  return api.put(`/php/versions/${encodeURIComponent(version)}/ini`, {
    contents,
    acknowledged: true,
  });
}
