import { api } from "@/lib/api/client";

// WordPress only. Both endpoints 404 on any other site type — the permission
// they are gated by exists solely in WordPressSiteType::features(), so the
// middleware resolves the site type without the controller naming it.

/**
 * The site's administrator accounts, read from WordPress itself rather than
 * from anything the panel recorded at install time. Roles change, and accounts
 * get added by people who never touch this panel.
 */
export async function getWordPressAdministrators(appId) {
  const res = await api.get(`/applications/${appId}/magic-login`);
  return res.data?.administrators ?? [];
}

/**
 * Mint a single-use, ~60 second token for one administrator.
 *
 * The token comes back exactly once and is never stored on this side — the
 * site keeps only its SHA-256 — so there is nothing to fetch again and no
 * second endpoint to read it from. Post it straight to the site.
 */
export async function createMagicLogin(appId, wpUserId) {
  const res = await api.post(`/applications/${appId}/magic-login`, {
    wp_user_id: wpUserId,
  });
  return res.data?.magic_login ?? null;
}
