/**
 * Deep links to where a token is created, and where it is revoked.
 *
 * Not from the API — the backend sends prose explaining which token to make,
 * and this turns that sentence into one click with the scopes already ticked.
 * The revoke link matters just as much: disconnecting here removes our copy of
 * a credential that stays live at the provider until it is deleted there.
 *
 * Self-hosted GitLab keeps its own token page, so the host is used when there
 * is one.
 */
const GITLAB_PATH = "/-/user_settings/personal_access_tokens";

export function createTokenUrl(provider, host, brand) {
  switch (provider) {
    case "github":
      // Scopes pre-ticked and the note pre-filled — this is the step people
      // get wrong, and the fix is a link, not a longer paragraph. The note is
      // the panel's own name because it is what the customer later reads on
      // GitHub's token list, long after they have forgotten making it.
      return `https://github.com/settings/tokens/new?scopes=repo&description=${encodeURIComponent(brand)}`;
    case "gitlab":
      return `${base(host, "https://gitlab.com")}${GITLAB_PATH}`;
    case "bitbucket":
      return "https://bitbucket.org/account/settings/app-passwords/new";
    default:
      return null;
  }
}

export function revokeTokenUrl(provider, host) {
  switch (provider) {
    case "github":
      return "https://github.com/settings/tokens";
    case "gitlab":
      return `${base(host, "https://gitlab.com")}${GITLAB_PATH}`;
    case "bitbucket":
      return "https://bitbucket.org/account/settings/app-passwords/";
    default:
      return null;
  }
}

/** A stored host may or may not carry a scheme or a trailing slash. */
function base(host, fallback) {
  if (!host) return fallback;
  const withScheme = /^https?:\/\//.test(host) ? host : `https://${host}`;
  return withScheme.replace(/\/+$/, "");
}
