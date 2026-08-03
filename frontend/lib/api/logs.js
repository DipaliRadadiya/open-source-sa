import { api } from "@/lib/api/client";

/**
 * The source catalog, re-read on an interval so sizes and the "written just
 * now" dots describe the box as it is rather than as it was on page load.
 */
export function listLogSources({ signal } = {}) {
  return api.get("/logs", { signal });
}

/**
 * Client-side read used for tailing, grep and reload.
 * `after` = the previous response's cursor → only newly-appended lines.
 */
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
