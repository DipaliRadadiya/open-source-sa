const CREDENTIALS = /^([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)([^/@]+)@/;

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

export function repositoryUrlProblem(raw) {
  const { url } = normalizeRepositoryUrl(raw);
  if (url === "") return null;

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
