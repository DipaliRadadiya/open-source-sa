import { api } from "@/lib/api/client";

// Writes the whole file. `restart` is sent when the app runs under systemd
// (requires_restart) so it picks up the new file. The response echoes the
// refreshed environment plus `applied`/`restarted` so the UI can confirm what
// actually happened. 422 on errors.raw carries syntax errors (verbatim).
export async function saveEnvironment(appId, { raw, restart = false }) {
  const res = await api.put(`/applications/${appId}/environment`, {
    raw,
    restart,
  });
  return res.data;
}

// What one logged change did, key by key, with the old and new value. Fetched
// on demand rather than with the history list: it reads one or two backup
// files off the server, and most rows are never expanded.
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
