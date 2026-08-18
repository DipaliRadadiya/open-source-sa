/**
 * The window between pressing "Back up now" and the run becoming visible.
 *
 * `POST /applications/{id}/backups` answers 202 with the backup *target*: the
 * backup row does not exist until a queue worker picks the job up. So the
 * refresh that follows the click returns the page that was already on screen —
 * no new row, nothing in flight, and therefore no polling either. Nothing on
 * the page moved, and the only signal was a toast that had already gone.
 *
 * These two functions are what closes that window: remember the newest id at
 * the moment of the click, and treat the wait as over as soon as something
 * newer appears.
 */

/**
 * The highest backup id in a list, or 0 for an empty one.
 *
 * Ids, not timestamps: `GET /backups` orders by `latest('id')` on an
 * auto-increment key, so a new run always carries the largest id in the list —
 * whereas `created_at` is only second-resolution and two runs can share one.
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
 * `queuedAfter` is the newest id from before the click, or null when nothing
 * was started. Deliberately not "is any backup in flight": a small site can be
 * backed up and finished between two five-second polls, and watching for a
 * pending/running status would then wait forever for a state that already
 * passed. Any newer id — whatever its status — means the run is on screen.
 */
export function isBackupQueued(backups, queuedAfter) {
  if (queuedAfter === null || queuedAfter === undefined) return false;
  return newestBackupId(backups) <= queuedAfter;
}

/**
 * The same question on a list that spans every site.
 *
 * `started` is `{ [applicationId]: newestIdForThatSiteAtTheClick }`. A site's
 * wait ends when a backup newer than its own mark appears — one site's run
 * finishing must not clear another site's wait, which a single flag would do.
 *
 * Returns the application ids still waiting, as strings (object keys).
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
