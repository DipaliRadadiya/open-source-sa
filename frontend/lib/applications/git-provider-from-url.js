/**
 * Which provider a PUBLIC repository URL belongs to, or null.
 *
 * A site created from a connected account already knows its provider and never
 * needs this. A site created by pasting a URL does not, so the deploy-on-push
 * card asked — a question whose answer was sitting in the URL on the same
 * screen for the three hosts almost everyone uses.
 *
 * Deliberately a closed list of exact hostnames, not a pattern.
 *
 * I have built host-matching as a stand-in for a missing provider field once
 * before, on storage destinations, and it became a source of confidently wrong
 * answers the moment a host did not fit the shape it assumed. It is safe here
 * only because these three hostnames ARE the providers — `github.com` cannot be
 * a GitLab install — and because anything else returns null and the picker
 * comes back. A self-hosted GitLab at `git.company.com` is unknowable from its
 * URL, and guessing there would be worse than asking.
 *
 * This decides a form DEFAULT, never verification. The webhook verifier reads
 * the stored `webhook_provider`, because a hook that re-sniffed the URL would
 * start rejecting real pushes the day someone repoints the repository.
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

  /*
   * SCP-style SSH (`git@github.com:owner/repo.git`) is what a provider's Clone
   * button offers beside the HTTPS one, and `new URL()` cannot parse it at all.
   * It is a perfectly identifiable host, so read it rather than giving up.
   */
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
