import { parseApiWallClock } from "../format/api-date.js";

/*
 * Mirrors `StaleBackupReaper::isStale`, the rule `POST /backups/{id}/clear`
 * enforces. Offering Clear before this point only earns a 422 ("may still be
 * running") — it was shown one second into a healthy run.
 *
 * The two windows are the backend's config defaults
 * (`no_heartbeat_stale_seconds`, and `upload_stall_seconds` plus the job's
 * unique-lock grace). Timestamps are UTC wall clock — `config/app.php` pins the
 * app timezone — so they are read as UTC, not in the browser's zone.
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
