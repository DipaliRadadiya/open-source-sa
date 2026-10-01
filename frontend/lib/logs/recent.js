// The API writes these in UTC with no zone; parse as a wall clock in UTC so
// server and browser renders agree (avoids a hydration mismatch).
export { parseApiWallClock as parseModified } from "../format/api-date.js";
import { parseApiWallClock as parseModified } from "../format/api-date.js";

// "Written to within the last few minutes", shown as a marker rather than a
// relative timestamp.
export const ACTIVE_WINDOW_MS = 5 * 60 * 1000;

export function isRecentlyActive(modified, now = Date.now()) {
  const date = parseModified(modified);
  if (!date) return false;
  const age = now - date.getTime();
  // Guard against clock skew putting the file slightly in the future.
  return age >= -60_000 && age <= ACTIVE_WINDOW_MS;
}
