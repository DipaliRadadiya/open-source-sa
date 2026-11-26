export function newestBackupId(backups = []) {
  return backups.reduce((max, backup) => {
    const id = Number(backup?.id);
    return Number.isFinite(id) && id > max ? id : max;
  }, 0);
}

export function isBackupQueued(backups, queuedAfter) {
  if (queuedAfter === null || queuedAfter === undefined) return false;
  return newestBackupId(backups) <= queuedAfter;
}

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
