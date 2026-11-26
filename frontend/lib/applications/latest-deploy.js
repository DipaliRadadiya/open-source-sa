export const WATCH_MS = 5000;
export const WATCH_BUSY_MS = 2500;

export function latestChanged(latest, top) {
  if (!latest) return false;
  if (!top) return true;
  return latest.id > top.id || (latest.id === top.id && latest.status !== top.status);
}

export function isNewPush(latest, top) {
  return Boolean(latest) && latest.trigger === "webhook" && (!top || latest.id > top.id);
}

export function watchDelay(latest, deploying) {
  return latest?.in_flight || deploying ? WATCH_BUSY_MS : WATCH_MS;
}
