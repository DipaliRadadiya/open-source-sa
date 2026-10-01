// The POST answers 202; the backup row exists only once a worker picks the job up.

// Ids, not timestamps: `created_at` has only second resolution.
export function newestBackupId(backups = []) {
  return backups.reduce((max, backup) => {
    const id = Number(backup?.id);
    return Number.isFinite(id) && id > max ? id : max;
  }, 0);
}

// `queuedAfter` is the newest id before the click, or null. Any newer id ends
// the wait, since a small backup can finish between two polls.
export function isBackupQueued(backups, queuedAfter) {
  if (queuedAfter === null || queuedAfter === undefined) return false;
  return newestBackupId(backups) <= queuedAfter;
}

// `started` maps applicationId to its newest id at the click. Returns ids as strings.
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
