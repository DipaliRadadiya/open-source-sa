const STAMP = /^(\d{2})-(\d{2})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/;

// Don't pass these to new Date(), the format doesn't parse reliably.
export function parseApiDate(value) {
  const m = String(value ?? "").match(STAMP);
  if (!m) return null;
  const [, dd, mm, yyyy, hh, min, ss] = m;
  const date = new Date(`${yyyy}-${mm}-${dd}T${hh}:${min}:${ss}`);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function apiDuration(start, end) {
  const from = parseApiDate(start);
  const to = parseApiDate(end);
  if (!from || !to) return null;

  const seconds = Math.round((to.getTime() - from.getTime()) / 1000);
  if (seconds < 0) return null;
  if (seconds < 60) return `${seconds}s`;

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) {
    const rest = seconds % 60;
    return rest ? `${minutes}m ${rest}s` : `${minutes}m`;
  }

  const hours = Math.floor(minutes / 60);
  return `${hours}h ${String(minutes % 60).padStart(2, "0")}m`;
}

export function parseApiWallClock(value) {
  const m = String(value ?? "").match(STAMP);
  if (!m) return null;
  const [, dd, mm, yyyy, hh, min, ss] = m.map(Number);
  const date = new Date(Date.UTC(yyyy, mm - 1, dd, hh, min, ss));
  return Number.isNaN(date.getTime()) ? null : date;
}
