/**
 * Split a domain so the part that identifies it survives truncation.
 *
 * A plain `truncate` keeps the BEGINNING, which on a domain is the least
 * identifying part. Measured at 390px, seven sites in the applications list all
 * rendered as "a-very-long-customer-subdomain-for-testi…" — the same string
 * seven times, on the one field that tells them apart. The site's own dashboard
 * wrapped the same domain onto two lines and showed it whole, so the panel
 * disagreed with itself on one screen.
 *
 * Splitting into a head that may truncate and a tail that never shrinks keeps
 * both ends: "a-very-long-customer-subdo….example.co.uk". No measurement and no
 * layout effect — flexbox does it, so it holds at any width.
 *
 * The tail is normally the last two labels. On a two-part public suffix that
 * would keep ".co.uk" — the suffix and nothing else, which identifies the site
 * no better than truncating did. So those take three labels and keep
 * "example.co.uk".
 *
 * A short list rather than the full public-suffix list: that is a large
 * dependency and a monthly update for a visual nicety, and these few cover the
 * suffixes a hosting panel actually meets. A miss costs one label of tail, not
 * correctness.
 */
const TWO_PART_SUFFIXES = new Set([
  "co.uk", "org.uk", "me.uk", "ltd.uk", "plc.uk", "net.uk", "sch.uk", "ac.uk", "gov.uk",
  "com.au", "net.au", "org.au", "edu.au", "gov.au",
  "co.nz", "net.nz", "org.nz",
  "co.za", "org.za", "web.za",
  "co.in", "net.in", "org.in", "firm.in", "gen.in", "ind.in",
  "co.jp", "or.jp", "ne.jp", "ac.jp", "go.jp",
  "com.br", "net.br", "org.br",
  "com.cn", "net.cn", "org.cn", "gov.cn",
  "com.mx", "com.ar", "com.sg", "com.my", "com.hk", "com.tr", "com.pl", "com.tw",
  "co.kr", "or.kr",
  "co.il", "org.il",
]);

export function splitDomain(domain) {
  const value = String(domain ?? "").trim();
  if (!value) return { head: "", tail: "" };

  const labels = value.split(".");
  const suffixLabels = TWO_PART_SUFFIXES.has(labels.slice(-2).join(".").toLowerCase()) ? 3 : 2;

  // Nothing to protect: the domain is already only its registrable part, and
  // splitting "example.com" into "" + "example.com" adds an empty element.
  if (labels.length <= suffixLabels) return { head: "", tail: value };

  return {
    head: labels.slice(0, -suffixLabels).join("."),
    tail: `.${labels.slice(-suffixLabels).join(".")}`,
  };
}
