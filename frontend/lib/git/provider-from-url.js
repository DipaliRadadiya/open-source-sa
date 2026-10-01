// Exact hosts only: a self-hosted GitLab is indistinguishable from Gitea or a bare SSH remote,
// so null is returned and the caller shows a generic mark.
const HOSTS = {
  "github.com": "github",
  "www.github.com": "github",
  "gitlab.com": "gitlab",
  "www.gitlab.com": "gitlab",
  "bitbucket.org": "bitbucket",
  "www.bitbucket.org": "bitbucket",
};

// `git@github.com:owner/repo.git` is not a parseable URL, but clone buttons hand it out.
const SCP_LIKE = /^[^@/\s]+@([^:/\s]+):/;

export function providerFromRepositoryUrl(url) {
  const value = String(url ?? "").trim();
  if (!value) return null;

  const scp = value.match(SCP_LIKE);
  if (scp) return HOSTS[scp[1].toLowerCase()] ?? null;

  try {
    // A bare `github.com/owner/repo` has no scheme and URL refuses it; people
    // paste what is in the address bar.
    const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : `https://${value}`;
    const { hostname } = new URL(withScheme);
    return HOSTS[hostname.toLowerCase()] ?? null;
  } catch {
    return null;
  }
}
