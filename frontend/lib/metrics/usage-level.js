/**
 * How busy a measured resource is, as one ladder.
 *
 * The level word and the bar colour used to be decided by two separate
 * threshold tests sitting next to each other. That is how a card ends up with
 * an amber bar beside the label "Normal" — nothing makes the second copy follow
 * the first when someone moves a number. Here the colour is derived FROM the
 * word, so they cannot drift apart.
 *
 * Plain logic with no JSX, so it lives in lib rather than beside the card that
 * renders it — which also means the ladder can be tested at its boundaries
 * without a browser.
 */

export function pct(value) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : 0;
}

/**
 * `normal` < 75 <= `watch` < 90 <= `high`, or null.
 *
 * Null when there is no percentage to judge — a machine with no swap, a disk
 * the collector could not read. Those are real states, but they are not usage
 * levels, and calling either of them "normal" would be a reassurance nobody
 * measured. The caller names them instead.
 */
export function usageStatus(percent) {
  if (percent == null) return null;
  const p = pct(percent);
  if (p >= 90) return "high";
  if (p >= 75) return "watch";
  return "normal";
}

const STATUS_TONE = { normal: "primary", watch: "warning", high: "destructive" };

export function usageTone(percent) {
  return STATUS_TONE[usageStatus(percent)] ?? "primary";
}
