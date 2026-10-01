import { postInNewTab } from "@/lib/browser/new-tab";

/**
 * Signs in to WordPress in a new tab. POST so the token never lands in a URL
 * (history, logs, Referer). Field name matches `resources/stubs/sv-magic-login.php`.
 */
export function openMagicLogin(session) {
  return postInNewTab(session.url, { sv_magic_login: session.token });
}
