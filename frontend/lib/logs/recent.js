// The API writes these with PHP's date() in the app timezone, UTC. Read as a
// wall clock in the browser's zone, "recent" came out differently on the
// server render and in an Indian or American browser, and React threw away
// the page's HTML over the mismatch.
export { parseApiWallClock as parseModified } from "../format/api-date.js";
import { parseApiWallClock as parseModified } from "../format/api-date.js";

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
