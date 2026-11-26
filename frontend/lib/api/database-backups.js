import { api } from "@/lib/api/client";

export function saveDatabaseBackupTarget(databaseId, payload) {
  return api.put(`/databases/${databaseId}/backup-target`, payload);
}

export function deleteDatabaseBackupTarget(databaseId, { deleteBackups = false } = {}) {
  return api.delete(`/databases/${databaseId}/backup-target`, {
    params: deleteBackups ? { delete_backups: 1 } : undefined,
  });
}

export function runDatabaseBackupNow(databaseId) {
  return api.post(`/databases/${databaseId}/backups`);
}

export function restoreDatabaseBackup(databaseId, backupId, confirm, safetyCopy = true) {
  return api.post(`/databases/${databaseId}/backups/${backupId}/restore`, { confirm, safety_copy: safetyCopy });
}
