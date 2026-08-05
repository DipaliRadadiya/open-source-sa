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

export async function restoreEnvironment(appId, { backup, restart = false }) {
  const res = await api.post(`/applications/${appId}/environment/restore`, {
    backup,
    restart,
  });
  return res.data;
}
