import { api } from "@/lib/api/client";

// A database on its own (no site) has its own schedule; a site's database is
// backed up with the site and these answer 422 for it. Permission: `database`.

export function saveDatabaseBackupTarget(databaseId, payload) {
  return api.put(`/databases/${databaseId}/backup-target`, payload);
}

// 422 while backups exist unless `deleteBackups`; each archive goes first.
export function deleteDatabaseBackupTarget(databaseId, { deleteBackups = false } = {}) {
  return api.delete(`/databases/${databaseId}/backup-target`, {
    params: deleteBackups ? { delete_backups: 1 } : undefined,
  });
}

// 202 before the row exists, like a site's run.
export function runDatabaseBackupNow(databaseId) {
  return api.post(`/databases/${databaseId}/backups`);
}

// Its own backup, or an archived one of the same engine. `confirm` is the
// database name. Needs this database's own schedule (for the safety backup).
export function restoreDatabaseBackup(databaseId, backupId, confirm) {
  return api.post(`/databases/${databaseId}/backups/${backupId}/restore`, { confirm });
}
