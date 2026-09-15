/**
 * Which service a repository URL points at, or null.
 *
 * A site built from a connected account carries `git_account_id` and the
 * account knows its provider. A site built from a public URL carries no
 * account at all — and the URL is then the only thing that says whether this
 * is GitHub, GitLab or Bitbucket. Reading the host is the whole trick, and it
 * is the one the backend recommended when it withdrew the column that would
 * have stored this: derive, do not store, unless the value drives behaviour.
 *
 * Only the three hosted services, and only by exact host. A self-hosted GitLab
 * at `git.example.com` is indistinguishable from a Gitea or a bare SSH remote
 * by its address, and guessing "gitlab" from the word "git" in a hostname
 * would put a GitLab badge on a repository that has nothing to do with them.
 * Null is the honest answer there, and the caller already has a generic mark
 * for it.
 */
const HOSTS = {
  "github.com": "github",
  "www.github.com": "github",
  "gitlab.com": "gitlab",
  "www.gitlab.com": "gitlab",
  "bitbucket.org": "bitbucket",
  "www.bitbucket.org": "bitbucket",
};

/**
 * `git@github.com:owner/repo.git` is not a URL any parser accepts, and it is
 * what half the clone buttons on those three sites hand you. The host is
 * everything between the `@` and the `:`.
 */
const SCP_LIKE = /^[^@/\s]+@([^:/\s]+):/;

export function providerFromRepositoryUrl(url) {
  const value = String(url ?? "").trim();
  if (!value) return null;

  const scp = value.match(SCP_LIKE);
  if (scp) return HOSTS[scp[1].toLowerCase()] ?? null;

  try {
    // A bare `github.com/owner/repo` has no scheme and URL refuses it; the
    // field accepts one, because people paste what is in the address bar.
    const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : `https://${value}`;
    const { hostname } = new URL(withScheme);
    return HOSTS[hostname.toLowerCase()] ?? null;
  } catch {
    return null;
  }
}
