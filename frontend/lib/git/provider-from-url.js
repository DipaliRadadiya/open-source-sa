const HOSTS = {
  "github.com": "github",
  "www.github.com": "github",
  "gitlab.com": "gitlab",
  "www.gitlab.com": "gitlab",
  "bitbucket.org": "bitbucket",
  "www.bitbucket.org": "bitbucket",
};

const SCP_LIKE = /^[^@/\s]+@([^:/\s]+):/;

export function providerFromRepositoryUrl(url) {
  const value = String(url ?? "").trim();
  if (!value) return null;

  const scp = value.match(SCP_LIKE);
  if (scp) return HOSTS[scp[1].toLowerCase()] ?? null;

  try {
    const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : `https://${value}`;
    const { hostname } = new URL(withScheme);
    return HOSTS[hostname.toLowerCase()] ?? null;
  } catch {
    return null;
  }
}
