/**
 * Deep links to where a token is created (scopes pre-ticked where possible)
 * and where it is revoked: disconnecting here does not revoke the token at the
 * provider. Self-hosted GitLab uses its own host.
 */
const GITLAB_PATH = "/-/user_settings/personal_access_tokens";

// Bitbucket app passwords were removed; tokens now live on the Atlassian
// account and are created via "Create API token with scopes", so unlike GitHub
// no scope can be passed in the URL.
const BITBUCKET_TOKENS = "https://id.atlassian.com/manage-profile/security/api-tokens";

export function createTokenUrl(provider, host, brand) {
  switch (provider) {
    case "github":
      // Scopes and note pre-filled; the note is the brand name the customer
      // later sees in GitHub's token list. `admin:repo_hook` lets the panel add
      // the deploy webhook itself.
      return `https://github.com/settings/tokens/new?scopes=repo,admin:repo_hook&description=${encodeURIComponent(brand)}`;
    case "gitlab":
      // `api` is the only GitLab scope that can add a webhook, and it is broad,
      // so the connect form says so beside this link.
      return `${base(host, "https://gitlab.com")}${GITLAB_PATH}?name=${encodeURIComponent(brand ?? "")}&scopes=api,read_repository`;
    case "bitbucket":
      return BITBUCKET_TOKENS;
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
      return BITBUCKET_TOKENS;
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
