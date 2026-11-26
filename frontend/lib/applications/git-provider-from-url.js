const HOSTS = {
  "github.com": "github",
  "www.github.com": "github",
  "gitlab.com": "gitlab",
  "www.gitlab.com": "gitlab",
  "bitbucket.org": "bitbucket",
  "www.bitbucket.org": "bitbucket",
};

export function gitProviderFromUrl(raw) {
  const value = typeof raw === "string" ? raw.trim() : "";
  if (value === "") return null;

  // new URL() can't parse SCP-style git@host:repo.
  const scp = /^[^\s/@]+@([^\s/:]+):/.exec(value);
  if (scp) return HOSTS[scp[1].toLowerCase()] ?? null;

  let host;
  try {
    host = new URL(value).hostname.toLowerCase();
  } catch {
    return null;
  }

  return HOSTS[host] ?? null;
}
