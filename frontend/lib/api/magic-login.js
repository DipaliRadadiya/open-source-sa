import { api } from "@/lib/api/client";

export async function getWordPressAdministrators(appId) {
  const res = await api.get(`/applications/${appId}/magic-login`);
  return res.data?.administrators ?? [];
}

export async function createMagicLogin(appId, wpUserId) {
  const res = await api.post(`/applications/${appId}/magic-login`, {
    wp_user_id: wpUserId,
  });
  return res.data?.magic_login ?? null;
}
