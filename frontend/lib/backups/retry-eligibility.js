export function backupTargetKey(backup) {
  if (backup?.application_id) return `application:${backup.application_id}`;
  if (backup?.database_id) return `database:${backup.database_id}`;
  return null;
}

export function isRetryable(backup, rows = [], known = null) {
  if (backup?.status !== "failed") return false;
  const key = backupTargetKey(backup);
  if (!key) return true;
  const newest = known instanceof Map ? known.get(key) : known?.[key];
  if (newest) return newest === backup.id;
  return !rows.some((row) => row.id > backup.id && backupTargetKey(row) === key);
}

export function newestBackupIds({ applications = [], databases = [] } = {}) {
  const known = new Map();
  for (const entry of applications) {
    if (entry?.application_id && entry.last_backup?.id) known.set(`application:${entry.application_id}`, entry.last_backup.id);
  }
  for (const row of databases) {
    if (row?.database?.id && row.lastBackup?.id) known.set(`database:${row.database.id}`, row.lastBackup.id);
  }
  return known;
}
