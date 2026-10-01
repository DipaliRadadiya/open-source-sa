import { parseApiWallClock } from "../format/api-date.js";

// Keep in step with `StaleBackupReaper::isStale` (`POST /backups/{id}/clear` enforces it).
// Windows are the backend defaults; timestamps are UTC wall clock.
const NO_HEARTBEAT_STALE_MS = 3900 * 1000;
const HEARTBEAT_GRACE_MS = (1200 + 300) * 1000;

const IN_FLIGHT = ["pending", "running", "verifying"];

export function isBackupStale(backup, now = Date.now()) {
  if (!IN_FLIGHT.includes(backup?.status)) return false;

  const heartbeat = parseApiWallClock(backup.progress_at);
  if (heartbeat) return now - heartbeat.getTime() > HEARTBEAT_GRACE_MS;

  const started = parseApiWallClock(backup.started_at) ?? parseApiWallClock(backup.created_at);
  return started !== null && now - started.getTime() > NO_HEARTBEAT_STALE_MS;
}
