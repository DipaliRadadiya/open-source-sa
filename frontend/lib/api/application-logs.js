import { api } from "@/lib/api/client";
import { applicationLogsResponseSchema } from "@/lib/schemas/application-log";

export async function listApplicationLogs(appId) {
  const res = await api.get(`/applications/${appId}/logs`);
  return applicationLogsResponseSchema.parse(res.data).logs;
}

export function readApplicationLog(appId, key, { lines, grep, filters, signal } = {}) {
  const set = Object.fromEntries(Object.entries(filters ?? {}).filter(([, value]) => value !== ""));
  return api.get(`/applications/${appId}/logs/${encodeURIComponent(key)}`, {
    params: {
      ...(lines ? { lines } : null),
      ...(grep ? { grep } : null),
      ...set,
    },
    signal,
  });
}

export function clearApplicationLog(appId, key) {
  return api.delete(`/applications/${appId}/logs/${encodeURIComponent(key)}`);
}

export function applicationLogDownloadUrl(appId, key) {
  return `${process.env.NEXT_PUBLIC_API_URL}/api/applications/${appId}/logs/${encodeURIComponent(key)}/download`;
}
