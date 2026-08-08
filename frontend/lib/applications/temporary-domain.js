/**
 * A domain for a site that does not have one yet.
 *
 * nip.io resolves `anything.<ip>.nip.io` to that IP without any DNS being set
 * up, so a new site is reachable the moment it finishes provisioning. That is
 * the whole point: the alternative is telling someone to buy a domain and wait
 * for propagation before they can see whether the thing they just made works.
 *
 * The IP is written with dashes rather than dots — both forms resolve, and the
 * dashed one is what the panel already serves itself on
 * (`sv-oss-app.167-233-229-184.nip.io`), so the site and the panel that made it
 * read as the same convention.
 */
export const NIP_IO_SUFFIX = "nip.io";

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
 * The full temporary domain, or null when it cannot be built.
 *
 * Falls back to `DEFAULT_TEMPORARY_LABEL` when the name yields no usable label,
 * so the caller always has something complete to show. Still null when there is
 * no address — `site..nip.io` is not a domain, and showing it would look like a
 * value that is one keystroke from working when it is not.
 */
export function temporaryDomain(name, ip, { fallbackLabel = DEFAULT_TEMPORARY_LABEL } = {}) {
  const host = ipToLabel(ip);
  if (!host) return null;

  const label = toDomainLabel(name) || toDomainLabel(fallbackLabel);
  if (!label) return null;

  return `${label}.${host}.${NIP_IO_SUFFIX}`;
}
