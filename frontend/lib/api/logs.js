import { api } from "@/lib/api/client";

/** The source catalog, re-read on an interval to keep sizes and activity current. */
export function listLogSources({ signal } = {}) {
  return api.get("/logs", { signal });
}

/** `after` = the previous response's cursor → only newly-appended lines. */
export function readLog(key, { lines, grep, after, signal } = {}) {
  return api.get(`/logs/${encodeURIComponent(key)}`, {
    params: {
      ...(lines ? { lines } : null),
      ...(grep ? { grep } : null),
      ...(after != null ? { after } : null),
    },
    signal,
  });
}

// The API streams the file with Content-Disposition, so a normal navigation
// lets the browser handle it (and sends the session cookie).
export function logDownloadUrl(key) {
  return `${process.env.NEXT_PUBLIC_API_URL}/api/logs/${encodeURIComponent(key)}/download`;
}

// Truncates, never deletes, so the writer keeps its handle. 404 for sources not marked
// `clearable`; read `clearable` off the source, never guess from its key.
export function clearLog(key) {
  return api.delete(`/logs/${encodeURIComponent(key)}`);
}
