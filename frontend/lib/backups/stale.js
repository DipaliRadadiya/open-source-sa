import { parseApiWallClock } from "../format/api-date.js";

/*
 * Keep in step with `StaleBackupReaper::isStale`, which `POST /backups/{id}/clear`
 * enforces (earlier attempts get a 422).
 *
 * Windows are the backend defaults (`no_heartbeat_stale_seconds`;
 * `upload_stall_seconds` plus the unique-lock grace). Timestamps are UTC wall
 * clock, so they are parsed as UTC, not in the browser's zone.
 */
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
