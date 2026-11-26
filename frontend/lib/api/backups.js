import { api } from "@/lib/api/client";

export function saveBackupTarget(applicationId, payload) {
  return api.put(`/applications/${applicationId}/backup-target`, payload);
}

export function deleteBackupTarget(applicationId, { deleteBackups = false } = {}) {
  return api.delete(`/applications/${applicationId}/backup-target`, {
    data: deleteBackups ? { delete_backups: true } : undefined,
  });
}

export function fetchBackupTargetOptions() {
  return api.get("/backup-targets/options");
}

export function runBackupNow(applicationId) {
  return api.post(`/applications/${applicationId}/backups`);
}

export function startRestore(backupId, payload) {
  return api.post(`/backups/${backupId}/restore`, payload);
}

export function fetchRestore(restoreId) {
  return api.get(`/restores/${restoreId}`);
}

// Open the url directly; axios headers would break the presigned signature.
export function fetchBackupDownload(backupId) {
  return api.get(`/backups/${backupId}/download`);
}

export function prepareBackupDownload(backupId) {
  return api.post(`/backups/${backupId}/download/prepare`);
}

export function fetchBackupDownloadPreparation(backupId, { signal } = {}) {
  return api.get(`/backups/${backupId}/download/prepare`, { signal });
}

export function fetchBackup(backupId) {
  return api.get(`/backups/${backupId}`);
}

export function retryBackup(backupId) {
  return api.post(`/backups/${backupId}/retry`);
}

export function clearStuckBackup(backupId) {
  return api.post(`/backups/${backupId}/clear`);
}

export function deleteBackups(ids) {
  return api.delete("/backups", { data: { ids } });
}
