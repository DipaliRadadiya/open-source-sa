/**
 * Watching for deploys this page did not start — a push, or someone else.
 *
 * The history is a server render, so the page only learns of a new run by
 * asking. `GET /deployments/latest` is the cheap question; these decide whether
 * its answer is news and how soon to ask again.
 */

export const WATCH_MS = 5000;
export const WATCH_BUSY_MS = 2500;

/** Whether the newest deploy differs from the history's top row. */
export function latestChanged(latest, top) {
  if (!latest) return false;
  if (!top) return true;
  return latest.id > top.id || (latest.id === top.id && latest.status !== top.status);
}

/** A run the history has not seen yet, started by a push. */
export function isNewPush(latest, top) {
  return Boolean(latest) && latest.trigger === "webhook" && (!top || latest.id > top.id);
}

/** Faster while something is running, as after pressing Deploy. */
export function watchDelay(latest, deploying) {
  return latest?.in_flight || deploying ? WATCH_BUSY_MS : WATCH_MS;
}
