// Moved to lib/format/api-date.js once a second feature needed it — every
// timestamp the API sends has this shape, not just a log file's mtime.
export { parseApiDate as parseModified } from "@/lib/format/api-date";
import { parseApiDate as parseModified } from "@/lib/format/api-date";

// "Written to within the last few minutes" is the question the rail should
// answer at a glance — a relative timestamp answers it worse and costs a
// quarter of the row's width.
export const ACTIVE_WINDOW_MS = 5 * 60 * 1000;

export function isRecentlyActive(modified, now = Date.now()) {
  const date = parseModified(modified);
  if (!date) return false;
  const age = now - date.getTime();
  // Guard against clock skew putting the file slightly in the future.
  return age >= -60_000 && age <= ACTIVE_WINDOW_MS;
}
