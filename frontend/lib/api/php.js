import { api } from "@/lib/api/client";

/** Sets what bare `php` resolves to. Sites keep the version their pool runs. */
export function setDefaultPhpVersion(version) {
  return api.put("/php/default", { default: version });
}

/**
 * Queued — apt takes minutes and holds a lock, so this returns 202 and the
 * caller polls. Idempotent: a version already installed returns 200.
 */
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
 * Replace the ini. `acknowledged` is required by the API by design — a raw ini
 * edit can stop FPM starting, so it must not be reachable by an accidental
 * request. The backend backs up, writes, runs `php-fpm{version} -t`, and
 * restores the previous file if PHP rejects it, reloading only that version.
 */
export function savePhpIni(version, contents) {
  return api.put(`/php/versions/${encodeURIComponent(version)}/ini`, {
    contents,
    acknowledged: true,
  });
}
