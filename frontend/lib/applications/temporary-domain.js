/**
 * A temporary domain for a new site via wildcard DNS (`name.<ip>.nip.io`), so
 * it is reachable as soon as provisioning finishes. The IP is dashed, matching
 * the panel's own hostname convention.
 */

/**
 * Fallback when `/server/capabilities` sends no `temporary_domain_suffixes`
 * (older backend or failed read); the server's list is authoritative.
 */
export const FALLBACK_TEMPORARY_SUFFIX = "nip.io";

/** Max DNS label length; longer names are cut, not refused. */
const MAX_LABEL = 63;

/**
 * A site name reduced to a DNS label: lowercase `a-z0-9-`, no leading, trailing
 * or repeated hyphens. Returns "" when nothing usable survives.
 */
export function toDomainLabel(name) {
  const label = String(name ?? "")
    .toLowerCase()
    .normalize("NFKD")
    // Strip accents so "Café" becomes "cafe" rather than "caf-".
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, MAX_LABEL);

  // Slicing can leave a trailing hyphen behind, which is not a legal label.
  return label.replace(/-+$/g, "");
}

/** `167.233.229.184` → `167-233-229-184`. Left alone if it is not an IPv4. */
export function ipToLabel(ip) {
  const trimmed = String(ip ?? "").trim();
  return /^\d{1,3}(\.\d{1,3}){3}$/.test(trimmed) ? trimmed.replace(/\./g, "-") : "";
}

/**
 * The label used before the site has a name, so the field is never empty.
 * Not translated: DNS labels are ASCII.
 */
export const DEFAULT_TEMPORARY_LABEL = "site";

/** The first suffix the server offers (backend order), or the fallback. */
export function preferredSuffix(suffixes) {
  const first = (Array.isArray(suffixes) ? suffixes : []).find(
    (suffix) => typeof suffix === "string" && suffix.trim(),
  );
  return first?.trim() || FALLBACK_TEMPORARY_SUFFIX;
}

/**
 * The full temporary domain, or null without a usable IPv4 address. Falls back
 * to `DEFAULT_TEMPORARY_LABEL` when the name yields no label.
 */
export function temporaryDomain(
  name,
  ip,
  { fallbackLabel = DEFAULT_TEMPORARY_LABEL, suffixes } = {},
) {
  const host = ipToLabel(ip);
  if (!host) return null;

  const label = toDomainLabel(name) || toDomainLabel(fallbackLabel);
  if (!label) return null;

  return `${label}.${host}.${preferredSuffix(suffixes)}`;
}

/**
 * Which domain tab the create form opens on: "temporary" whenever the server
 * has an IPv4 address. Without one the toggle is hidden, so it must open on
 * "own" or the field is stuck read-only and empty.
 */
export function initialDomainMode({ serverIp } = {}) {
  return ipToLabel(serverIp) ? "temporary" : "own";
}
