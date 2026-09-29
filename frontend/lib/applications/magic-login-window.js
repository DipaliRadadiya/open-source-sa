import { postInNewTab } from "@/lib/browser/new-tab";

/**
 * Signs in to WordPress in a new tab. A POST, because the token must not sit
 * in a URL — history, logs and the Referer header all keep URLs. The field
 * name matches the mu-plugin (`resources/stubs/sv-magic-login.php`).
 */
export function openMagicLogin(session) {
  return postInNewTab(session.url, { sv_magic_login: session.token });
}
