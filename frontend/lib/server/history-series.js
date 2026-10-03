// `sampled_at` is `d-m-Y H:i:s` (day first, which `new Date()` misreads), parsed by position.
// The backend writes it in UTC (app.timezone), with no offset. Read as the browser's own
// clock it was off by the browser's offset — a Kolkata browser saw the charts 5h30 early.
// The charts then format it in the server's zone.
export function sampleTime(value) {
  const match = /^(\d{2})-(\d{2})-(\d{4})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(
    String(value ?? "").trim(),
  );
  if (!match) return null;
  const [, day, month, year, hour, minute, second] = match.map(Number);
  const at = Date.UTC(year, month - 1, day, hour, minute, second);
  return Number.isNaN(at) ? null : at;
}

// A stretch with no samples (the server was down) longer than this many usual steps
// becomes a break in the line rather than a confident curve across it.
const GAP_STEPS = 2.5;

// `t` in milliseconds; sorted oldest first since an unsorted array draws garbage silently.
export function historySeries(points) {
  const sorted = (points ?? [])
    .map((point) => ({ ...point, t: sampleTime(point.sampled_at) }))
    .filter((point) => point.t !== null)
    .sort((a, b) => a.t - b.t);
  return withGaps(sorted);
}

// The break is a point with no values: every series maps a missing key to null, and
// the charts draw with connectNulls off.
function withGaps(points) {
  if (points.length < 3) return points;
  const steps = points
    .slice(1)
    .map((point, index) => point.t - points[index].t)
    .filter((step) => step > 0)
    .sort((a, b) => a - b);
  const usual = steps[Math.floor(steps.length / 2)];
  if (!usual) return points;

  const out = [points[0]];
  for (let index = 1; index < points.length; index++) {
    if (points[index].t - points[index - 1].t > usual * GAP_STEPS) {
      out.push({ t: points[index - 1].t + usual, gap: true });
    }
    out.push(points[index]);
  }
  return out;
}
