// Revoke links too: disconnecting here does not revoke the token at the provider.
const GITLAB_PATH = "/-/user_settings/personal_access_tokens";

// Atlassian API tokens take no scopes in the URL.
const BITBUCKET_TOKENS = "https://id.atlassian.com/manage-profile/security/api-tokens";

export function createTokenUrl(provider, host, brand) {
  switch (provider) {
    case "github":
      // The note is the brand name shown in GitHub's token list; `admin:repo_hook` lets
      // the panel add the deploy webhook.
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
