/**
 * Tidies a pasted public repository URL into one the API accepts. Bitbucket's
 * Clone button includes a username (`https://you@bitbucket.org/…`), which the
 * API refuses; for a public repository it is meaningless, so it is stripped.
 */

/** A URL's `user[:pass]@` prefix, if it has one. */
const CREDENTIALS = /^([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)([^/@]+)@/;

/**
 * @returns {{ url: string, strippedCredentials: boolean }}
 *   `strippedCredentials` is true when a username was removed, so the UI can say so.
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
 * Why this URL cannot be used, or null. The server still decides; this only
 * catches certain cases early. Deliberately NOT a host allowlist: the server
 * does the SSRF checks.
 */
export function repositoryUrlProblem(raw) {
  const { url } = normalizeRepositoryUrl(raw);
  if (url === "") return null;

  // SSH clone URLs (SCP-style or ssh://) get "use HTTPS" rather than "malformed".
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
