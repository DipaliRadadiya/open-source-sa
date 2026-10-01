/**
 * How busy a measured resource is, as one ladder. The bar colour is derived
 * from the level word so the two cannot drift apart.
 */

export function pct(value) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : 0;
}

/**
 * `normal` < 75 <= `watch` < 90 <= `high`, or null when there is no
 * percentage (no swap, unreadable disk). Never report those as "normal";
 * the caller names them.
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
