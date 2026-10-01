import { api } from "@/lib/api/client";
import { envHistoryResponseSchema } from "@/lib/schemas/environment";

// Writes the whole file. `restart` is sent when `requires_restart`. The response
// carries the refreshed environment plus `applied`/`restarted`; a 422 on
// errors.raw carries syntax errors verbatim.
export async function saveEnvironment(appId, { raw, restart = false }) {
  const res = await api.put(`/applications/${appId}/environment`, {
    raw,
    restart,
  });
  return res.data;
}

// One logged change, key by key with old and new values. Fetched on demand
// because it reads backup files.
export async function getEnvironmentDiff(appId, logId) {
  const res = await api.get(
    `/applications/${appId}/environment/history/${logId}/diff`,
  );
  return res.data;
}

export async function restoreEnvironment(appId, { backup, restart = false }) {
  const res = await api.post(`/applications/${appId}/environment/restore`, {
    backup,
    restart,
  });
  return res.data;
}

// One more page of history for "Show older changes", parsed with the same
// schema as the first page.
export async function getEnvironmentHistoryPage(appId, page) {
  const res = await api.get(`/applications/${appId}/environment/history`, {
    params: { page },
  });
  return envHistoryResponseSchema.parse(res.data);
}
