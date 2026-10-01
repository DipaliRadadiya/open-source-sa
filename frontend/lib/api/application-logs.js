import { api } from "@/lib/api/client";

/**
 * Read one of a site's log sources. No `after` cursor exists for app logs, so
 * live-tail re-reads the last N lines (throttle 120/min). `grep` is a literal,
 * case-insensitive substring applied server-side over the whole file.
 */
export function readApplicationLog(appId, key, { lines, grep, signal } = {}) {
  return api.get(`/applications/${appId}/logs/${encodeURIComponent(key)}`, {
    params: {
      ...(lines ? { lines } : null),
      ...(grep ? { grep } : null),
    },
    signal,
  });
}

/**
 * Empty one of a site's logs (truncated, never deleted, so the writer keeps
 * its handle). Resolves to the emptied source.
 *
 * Needs `app_log` manage (403 otherwise). 404 means no such source; the key is
 * resolved against the catalogue, never used as a path.
 */
export function clearApplicationLog(appId, key) {
  return api.delete(`/applications/${appId}/logs/${encodeURIComponent(key)}`);
}
