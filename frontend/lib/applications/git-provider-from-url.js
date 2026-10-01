/**
 * Which provider a public repository URL belongs to, or null.
 *
 * Deliberately a closed list of exact hostnames, not a pattern: self-hosted
 * instances cannot be identified from a URL, so they return null and the user
 * picks. This only sets a form default; webhook verification uses the stored
 * `webhook_provider`.
 */

/** Exact hosts, and the `www.` form each one also answers on. */
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

  // SCP-style SSH (`git@github.com:owner/repo.git`) cannot be parsed by `new URL()`.
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
