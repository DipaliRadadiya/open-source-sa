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
