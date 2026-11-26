export function sampleTime(value) {
  const match = /^(\d{2})-(\d{2})-(\d{4})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(
    String(value ?? "").trim(),
  );
  if (!match) return null;
  const [, day, month, year, hour, minute, second] = match.map(Number);
  const at = Date.UTC(year, month - 1, day, hour, minute, second);
  return Number.isNaN(at) ? null : at;
}

const GAP_STEPS = 2.5;

export function historySeries(points) {
  const sorted = (points ?? [])
    .map((point) => ({ ...point, t: sampleTime(point.sampled_at) }))
    .filter((point) => point.t !== null)
    .sort((a, b) => a.t - b.t);
  return withGaps(sorted);
}

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
