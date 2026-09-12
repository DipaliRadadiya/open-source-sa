import { api } from "@/lib/api/client";

/**
 * Read one of a site's log sources. No `after` cursor exists for app logs, so
 * live-tail is a plain re-read of the last N lines (throttle 120/min). `grep`
 * is a literal case-insensitive substring, applied server-side over the whole
 * file — cheap even on a large log, so preferred over client filtering.
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
 * Empty one of a site's logs.
 *
 * Truncated server-side, never deleted — the writer keeps its file handle, so
 * nginx or the Node unit carries on appending to the same inode with the same
 * owner. Resolves to the source in its emptied state, so the caller can render
 * it rather than re-fetching.
 *
 * `app_log` **manage**; a viewer gets 403. 404 means the site has no such
 * source, which is also what a path aimed anywhere else returns — the key is
 * resolved against the catalogue, never used as a path.
 */
export function clearApplicationLog(appId, key) {
  return api.delete(`/applications/${appId}/logs/${encodeURIComponent(key)}`);
}
