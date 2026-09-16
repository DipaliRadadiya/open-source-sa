/**
 * Tidy a pasted public repository URL into one the API will accept.
 *
 * Bitbucket's own Clone button hands out `https://you@bitbucket.org/team/repo.git`
 * — the username is baked into the URL. The API refuses any URL carrying a
 * user component, because that field ends up in `git clone` and a URL that can
 * carry a username can carry a password beside it. That guard is right.
 *
 * What was wrong is what the person saw: a valid Bitbucket URL, pasted exactly
 * as Bitbucket gave it, rejected with "Enter a valid https:// URL for the
 * self-hosted instance" — wording written for the self-hosted GitLab host
 * field, which says nothing about the URL in front of them.
 *
 * For a PUBLIC repository the username prefix carries no meaning: cloning
 * works identically without it. So it is removed rather than complained about.
 * A private repository is a different flow entirely — the connected-account
 * source — and dropping a username cannot make that one work, which is why
 * this never tries to.
 */

/** A URL's `user[:pass]@` prefix, if it has one. */
const CREDENTIALS = /^([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)([^/@]+)@/;

/**
 * @returns {{ url: string, strippedCredentials: boolean }}
 *   `url` is what should be submitted. `strippedCredentials` is true when a
 *   username was removed, so the screen can say so rather than silently
 *   editing what someone typed.
 */
export function normalizeRepositoryUrl(raw) {
  const trimmed = typeof raw === "string" ? raw.trim() : "";
  if (trimmed === "") return { url: "", strippedCredentials: false };

  const match = trimmed.match(CREDENTIALS);
  if (!match) return { url: trimmed, strippedCredentials: false };

  return {
    url: trimmed.replace(CREDENTIALS, "$1"),
    strippedCredentials: true,
  };
}

/**
 * Why this URL cannot be used, or null.
 *
 * Checked here as well as on the server so the answer arrives while the field
 * is in front of you, in words about a repository URL rather than about a
 * self-hosted instance. The server still decides — this only stops the
 * round-trip for the cases it can be sure about.
 *
 * Deliberately NOT a host allowlist: a public git URL can live anywhere, and
 * the server does the SSRF range checks that actually matter.
 */
export function repositoryUrlProblem(raw) {
  const { url } = normalizeRepositoryUrl(raw);
  if (url === "") return null;

  /*
   * An SSH clone URL is the other thing the provider's Clone button offers, so
   * it gets pasted here regularly. `new URL()` cannot parse the SCP-style form
   * at all, which would make this answer "malformed" — true but useless. What
   * the person needs to know is that this field wants the HTTPS one.
   */
  if (/^[^\s/]+@[^\s/]+:/.test(url) || url.startsWith("ssh://")) return "notHttps";

  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return "malformed";
  }

  if (parsed.protocol !== "https:") return "notHttps";
  if (!parsed.hostname) return "malformed";

  return null;
}
