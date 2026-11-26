export { parseApiWallClock as parseModified } from "../format/api-date.js";
import { parseApiWallClock as parseModified } from "../format/api-date.js";

export const ACTIVE_WINDOW_MS = 5 * 60 * 1000;

export function isRecentlyActive(modified, now = Date.now()) {
  const date = parseModified(modified);
  if (!date) return false;
  const age = now - date.getTime();
  return age >= -60_000 && age <= ACTIVE_WINDOW_MS;
}
