import { api } from "@/lib/api/client";

// No `after` cursor, so live-tail re-reads the last N lines (throttle 120/min).
// `grep` is a literal, case-insensitive substring applied server-side.
export function readApplicationLog(appId, key, { lines, grep, signal } = {}) {
  return api.get(`/applications/${appId}/logs/${encodeURIComponent(key)}`, {
    params: {
      ...(lines ? { lines } : null),
      ...(grep ? { grep } : null),
    },
    signal,
  });
}

// Truncated, never deleted, so the writer keeps its handle. Needs `app_log` manage.
export function clearApplicationLog(appId, key) {
  return api.delete(`/applications/${appId}/logs/${encodeURIComponent(key)}`);
}
