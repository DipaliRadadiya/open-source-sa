import { postInNewTab } from "@/lib/browser/new-tab";

// POST so the token never ends up in a URL. The field name must match sv-magic-login.php.
export function openMagicLogin(session) {
  return postInNewTab(session.url, { sv_magic_login: session.token });
}
