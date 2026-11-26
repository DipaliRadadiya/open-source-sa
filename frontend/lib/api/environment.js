import { api } from "@/lib/api/client";
import { envHistoryResponseSchema } from "@/lib/schemas/environment";

export async function saveEnvironment(appId, { raw, restart = false, safetyCopy = true }) {
  const res = await api.put(`/applications/${appId}/environment`, {
    raw,
    restart,
    safety_copy: safetyCopy,
  });
  return res.data;
}

export async function checkEnvironment(appId, raw) {
  const res = await api.post(`/applications/${appId}/environment/check`, { raw });
  return res.data;
}

export async function getEnvironmentDiff(appId, logId) {
  const res = await api.get(
    `/applications/${appId}/environment/history/${logId}/diff`,
  );
  return res.data;
}

export async function restoreEnvironment(appId, { backup, restart = false, safetyCopy = true }) {
  const res = await api.post(`/applications/${appId}/environment/restore`, {
    backup,
    restart,
    safety_copy: safetyCopy,
  });
  return res.data;
}

export async function getEnvironmentHistoryPage(appId, page) {
  const res = await api.get(`/applications/${appId}/environment/history`, {
    params: { page },
  });
  return envHistoryResponseSchema.parse(res.data);
}
