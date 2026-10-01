/**
 * The window between pressing "Back up now" and the run appearing in the list.
 *
 * The POST answers 202 with the target; the backup row only exists once a
 * worker picks the job up. So remember the newest id at the click and treat
 * the wait as over once something newer appears.
 */

/**
 * The highest backup id in a list, or 0 for an empty one. Ids, not timestamps:
 * ids are auto-increment, while `created_at` has only second resolution.
 */
export function newestBackupId(backups = []) {
  return backups.reduce((max, backup) => {
    const id = Number(backup?.id);
    return Number.isFinite(id) && id > max ? id : max;
  }, 0);
}

/**
 * Is a run started here still invisible in the list?
 *
 * `queuedAfter` is the newest id before the click, or null when nothing was
 * started. Checks for any newer id rather than an in-flight status, since a
 * small backup can finish between two polls.
 */
export function isBackupQueued(backups, queuedAfter) {
  if (queuedAfter === null || queuedAfter === undefined) return false;
  return newestBackupId(backups) <= queuedAfter;
}

/**
 * The same question across every site. `started` maps applicationId to its
 * newest id at the click, so one site's run cannot clear another's wait.
 * Returns the application ids still waiting, as strings.
 */
export function queuedApplications(backups = [], started = {}) {
  const newest = new Map();
  for (const backup of backups) {
    const app = Number(backup?.application_id);
    const id = Number(backup?.id);
    if (!Number.isFinite(app) || !Number.isFinite(id)) continue;
    if (id > (newest.get(app) ?? 0)) newest.set(app, id);
  }

  return Object.keys(started).filter((app) => (newest.get(Number(app)) ?? 0) <= started[app]);
}
