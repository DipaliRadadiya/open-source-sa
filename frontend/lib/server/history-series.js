// `sampled_at` is `d-m-Y H:i:s` (day first, which `new Date()` misreads), parsed by position.
// It has no offset: read as local time, then formatted in the server's zone.
export function sampleTime(value) {
  const match = /^(\d{2})-(\d{2})-(\d{4})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(
    String(value ?? "").trim(),
  );
  if (!match) return null;
  const [, day, month, year, hour, minute, second] = match.map(Number);
  const at = new Date(year, month - 1, day, hour, minute, second);
  return Number.isNaN(at.getTime()) ? null : at.getTime();
}

// `t` in milliseconds; sorted oldest first since an unsorted array draws garbage silently.
export function historySeries(points) {
  return (points ?? [])
    .map((point) => ({ ...point, t: sampleTime(point.sampled_at) }))
    .filter((point) => point.t !== null)
    .sort((a, b) => a.t - b.t);
}
