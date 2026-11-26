const GITLAB_PATH = "/-/user_settings/personal_access_tokens";

const BITBUCKET_TOKENS = "https://id.atlassian.com/manage-profile/security/api-tokens";

export function createTokenUrl(provider, host, brand) {
  switch (provider) {
    case "github":
      return `https://github.com/settings/tokens/new?scopes=repo,admin:repo_hook&description=${encodeURIComponent(brand)}`;
    case "gitlab":
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

function base(host, fallback) {
  if (!host) return fallback;
  const withScheme = /^https?:\/\//.test(host) ? host : `https://${host}`;
  return withScheme.replace(/\/+$/, "");
}
