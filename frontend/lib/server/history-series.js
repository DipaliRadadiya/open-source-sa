/**
 * The 24h collector's samples, ready for Recharts.
 *
 * `sampled_at` arrives as `d-m-Y H:i:s` — DAY first. `new Date("11-08-2026")`
 * reads that as month-first, so 11 August silently becomes 8 November, and
 * anything past the 12th of a month is simply Invalid Date. Every field has to
 * be parsed by position.
 *
 * The string carries no offset, so it is read as local time and then formatted
 * with the server's zone by `clockFormatter` — the same arrangement the
 * database history chart uses.
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
 * `t` in milliseconds so the charts share one x-axis key with the live series,
 * oldest first — the collector orders its rows, but a chart drawn from an
 * unsorted array draws a scribble rather than failing, so it is not worth
 * trusting.
 */
export function historySeries(points) {
  return (points ?? [])
    .map((point) => ({ ...point, t: sampleTime(point.sampled_at) }))
    .filter((point) => point.t !== null)
    .sort((a, b) => a.t - b.t);
}
