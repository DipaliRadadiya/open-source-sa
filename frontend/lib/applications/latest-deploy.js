/**
 * Polls `GET /deployments/latest` for deploys this page did not start (a push
 * or another user), since the history is server-rendered.
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
