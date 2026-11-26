import { api } from "@/lib/api/client";

export function listLogSources({ signal } = {}) {
  return api.get("/logs", { signal });
}

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

export function logDownloadUrl(key) {
  return `${process.env.NEXT_PUBLIC_API_URL}/api/logs/${encodeURIComponent(key)}/download`;
}

// Truncates instead of deleting so the writer keeps its file handle.
export function clearLog(key) {
  return api.delete(`/logs/${encodeURIComponent(key)}`);
}
