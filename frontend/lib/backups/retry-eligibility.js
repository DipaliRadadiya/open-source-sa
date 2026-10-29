// Retry re-runs a target with its settings and leaves the failed row as it was (it stays
// `failed` for good). So only a target's newest backup offers Retry: an older failed row is
// history, already followed by another run, and retrying it again starts yet another backup.

/** The site or database a backup belongs to; null for an archived row with neither. */
export function backupTargetKey(backup) {
  if (backup?.application_id) return `application:${backup.application_id}`;
  if (backup?.database_id) return `database:${backup.database_id}`;
  return null;
}

/**
 * Whether a failed backup is its target's newest, so Retry makes sense.
 * `known`: target key → id of its newest backup, read without filters or pages (coverage);
 * a Map or a plain object (a server page cannot hand a Map to a client component).
 * Without one, the newest in `rows` decides — exact on a single target's own list, which is
 * newest first and unfiltered.
 */
export function isRetryable(backup, rows = [], known = null) {
  if (backup?.status !== "failed") return false;
  const key = backupTargetKey(backup);
  // Nothing to compare with: keep offering it, as before.
  if (!key) return true;
  const newest = known instanceof Map ? known.get(key) : known?.[key];
  if (newest) return newest === backup.id;
  return !rows.some((row) => row.id > backup.id && backupTargetKey(row) === key);
}

/** Newest backup id per target, from coverage (`last_backup` is the newest run however it ended). */
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
