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
