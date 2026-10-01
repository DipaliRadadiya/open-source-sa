/**
 * Split a domain into a head that may truncate and a tail that never shrinks,
 * so the identifying end survives ("a-very-long-customer-subdo….example.co.uk").
 * Pure flexbox, no measurement.
 *
 * The tail is the last two labels, or three for a two-part public suffix
 * (so "example.co.uk", not ".co.uk"). A short list instead of the full
 * public-suffix list: a miss costs one label of tail, not correctness.
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

  // Already only the registrable part: nothing to split.
  if (labels.length <= suffixLabels) return { head: "", tail: value };

  return {
    head: labels.slice(0, -suffixLabels).join("."),
    tail: `.${labels.slice(-suffixLabels).join(".")}`,
  };
}
