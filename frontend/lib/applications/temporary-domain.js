/**
 * A domain for a site that does not have one yet.
 *
 * A wildcard-DNS host resolves `anything.<ip>.nip.io` to that IP without any
 * DNS being set up, so a new site is reachable the moment it finishes
 * provisioning. That is the whole point: the alternative is telling someone to
 * buy a domain and wait for propagation before they can see whether the thing
 * they just made works.
 *
 * The IP is written with dashes rather than dots — both forms resolve, and the
 * dashed one is what the panel already serves itself on
 * (`sv-oss-app.167-233-229-184.nip.io`), so the site and the panel that made it
 * read as the same convention.
 */

/**
 * Used only when the server names no suffix of its own.
 *
 * `/server/capabilities` returns `temporary_domain_suffixes` (today
 * `["nip.io", "sslip.io"]`) and that list is authoritative — it is the server
 * saying which hosts IT will answer for. This constant exists so an older
 * backend, or a failed capabilities read, still produces a working domain
 * rather than none.
 */
export const FALLBACK_TEMPORARY_SUFFIX = "nip.io";

/**
 * The longest a single DNS label may be. A name longer than this is cut rather
 * than refused: the label is derived from something the user typed for another
 * purpose, so it should bend to the rule instead of making them fight it.
 */
const MAX_LABEL = 63;

/**
 * A site name reduced to something that can stand as a DNS label.
 *
 * Lowercase, `a-z0-9-` only, no leading or trailing hyphen and no runs of
 * them. Returns "" when nothing usable survives — a name of "!!!" has no
 * label in it, and inventing one would produce a domain the user never chose.
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
 * What the domain is called before the site has a name.
 *
 * The field is filled from the moment the option is chosen rather than sitting
 * empty until a name is typed: an empty box under a control you just switched
 * on reads as broken. This is a real, working domain — it is simply replaced
 * as soon as there is a name to build a better one from.
 *
 * Not translated: it is a DNS label, and those are ASCII.
 */
export const DEFAULT_TEMPORARY_LABEL = "site";

/**
 * The first suffix the server offers, or the fallback when it offers none.
 *
 * The list is ordered by the backend; there is no choice to put in front of
 * anyone here — nip.io and sslip.io do the identical job, and asking which
 * wildcard-DNS provider they would like is a question nobody has an answer to.
 */
export function preferredSuffix(suffixes) {
  const first = (Array.isArray(suffixes) ? suffixes : []).find(
    (suffix) => typeof suffix === "string" && suffix.trim(),
  );
  return first?.trim() || FALLBACK_TEMPORARY_SUFFIX;
}

/**
 * The full temporary domain, or null when it cannot be built.
 *
 * Falls back to `DEFAULT_TEMPORARY_LABEL` when the name yields no usable label,
 * so the caller always has something complete to show. Still null when there is
 * no address — `site..nip.io` is not a domain, and showing it would look like a
 * value that is one keystroke from working when it is not.
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
 * Which domain tab the create form opens on.
 *
 * "temporary" whenever this server can build one. A new site is far more often
 * made before its domain is pointed than after, and the temporary host works
 * the moment provisioning finishes — so opening on "own" asked most people to
 * switch tabs before they could get anywhere.
 *
 * The condition is the address, not the suffix: the suffix always resolves via
 * FALLBACK_TEMPORARY_SUFFIX, while a missing or non-IPv4 address makes
 * `temporaryDomain` return null AND hides the own/temporary toggle. Defaulting
 * to "temporary" there would leave the field readOnly and empty with no
 * control on screen to escape it, so that case still opens on "own".
 */
export function initialDomainMode({ serverIp } = {}) {
  return ipToLabel(serverIp) ? "temporary" : "own";
}
