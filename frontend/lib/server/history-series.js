/**
 * Parses the 24h collector's `sampled_at`, sent as `d-m-Y H:i:s` (day first,
 * which `new Date()` misreads), by position. It has no offset: read as local
 * time, then formatted in the server's zone by `clockFormatter`.
 */
export function sampleTime(value) {
  const match = /^(\d{2})-(\d{2})-(\d{4})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(
    String(value ?? "").trim(),
  );
  if (!match) return null;
  const [, day, month, year, hour, minute, second] = match.map(Number);
  const at = new Date(year, month - 1, day, hour, minute, second);
  return Number.isNaN(at.getTime()) ? null : at.getTime();
}

/**
 * Samples for Recharts: `t` in milliseconds (shared x-axis key with the live
 * series), sorted oldest first since an unsorted array draws garbage silently.
 */
export function historySeries(points) {
  return (points ?? [])
    .map((point) => ({ ...point, t: sampleTime(point.sampled_at) }))
    .filter((point) => point.t !== null)
    .sort((a, b) => a.t - b.t);
}
