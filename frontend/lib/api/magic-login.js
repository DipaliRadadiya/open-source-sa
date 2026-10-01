import { api } from "@/lib/api/client";

// WordPress only: both endpoints 404 on any other site type.

/** The site's administrator accounts, read live from WordPress. */
export async function getWordPressAdministrators(appId) {
  const res = await api.get(`/applications/${appId}/magic-login`);
  return res.data?.administrators ?? [];
}

// Single-use, ~60s token returned exactly once (the site stores only its hash); post it straight to the site.
export async function createMagicLogin(appId, wpUserId) {
  const res = await api.post(`/applications/${appId}/magic-login`, {
    wp_user_id: wpUserId,
  });
  return res.data?.magic_login ?? null;
}
