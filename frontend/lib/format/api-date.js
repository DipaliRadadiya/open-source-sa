// The API sends "DD-MM-YYYY HH:mm:ss" (not ISO), in the server's timezone.
const STAMP = /^(\d{2})-(\d{2})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/;

/**
 * One of the API's timestamps as a Date, or null if it is not one.
 *
 * `new Date("01-08-2026 09:00:00")` is a parse error in every engine that does
 * not silently read it as a US date, so nothing may hand these to Date directly.
 */
export function parseApiDate(value) {
  const m = String(value ?? "").match(STAMP);
  if (!m) return null;
  const [, dd, mm, yyyy, hh, min, ss] = m;
  const date = new Date(`${yyyy}-${mm}-${dd}T${hh}:${min}:${ss}`);
  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * How long something took, as "45s" / "2m 14s" / "1h 03m", or null when either
 * end is missing or the pair makes no sense.
 *
 * Both timestamps come from the same clock in the same format, so the
 * difference is meaningful even though neither carries a timezone. The unit
 * letters stay untranslated — the same convention the panel uses for other
 * technical tokens, and h/m/s read the same in every locale we ship.
 */
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
