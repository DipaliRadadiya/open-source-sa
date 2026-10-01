import { api } from "@/lib/api/client";

/** One target per application, so create and update are the same call. */
export function saveBackupTarget(applicationId, payload) {
  return api.put(`/applications/${applicationId}/backup-target`, payload);
}

// 422s while backups exist unless `deleteBackups` is set; pausing keeps the archives.
// Needs `backup` manage, not `app_backup`.
export function deleteBackupTarget(applicationId, { deleteBackups = false } = {}) {
  return api.delete(`/applications/${applicationId}/backup-target`, {
    data: deleteBackups ? { delete_backups: true } : undefined,
  });
}

/** The settings form's choices, for a retry after the server render could not read them. */
export function fetchBackupTargetOptions() {
  return api.get("/backup-targets/options");
}

// Throttled 6/min. Answers 202 with the target, not a backup: the row exists only once
// a worker picks the job up. 422 when the site has no target or a run is in flight.
export function runBackupNow(applicationId) {
  return api.post(`/applications/${applicationId}/backups`);
}

// Throttled 2/min. `confirm` must equal the domain exactly and `type` be a subset of
// the archive's contents. Answers 202 with the restore row.
export function startRestore(backupId, payload) {
  return api.post(`/backups/${backupId}/restore`, payload);
}

/** Poll one restore while it runs. Throttled at 120/min. */
export function fetchRestore(restoreId) {
  return api.get(`/restores/${restoreId}`);
}

// Throttled 6/min; needs `backup,manage`. Navigate to `url` directly: Axios would add
// headers and break the presigned signature.
export function fetchBackupDownload(backupId) {
  return api.get(`/backups/${backupId}/download`);
}

/** Used for a restore's safety copy, whose type decides what an undo can put back. */
export function fetchBackup(backupId) {
  return api.get(`/backups/${backupId}`);
}

/** Throttled 6/min. Repeats the failed run with the same configuration. */
export function retryBackup(backupId) {
  return api.post(`/backups/${backupId}/retry`);
}

// Not a cancel: the API refuses a job that may still be writing, since freeing the
// per-site guard early could allow two writers on the same backup key.
export function clearStuckBackup(backupId) {
  return api.post(`/backups/${backupId}/clear`);
}

/** The server removes the archive before the record, so nothing is orphaned in the bucket. */
export function deleteBackup(backupId) {
  return api.delete(`/backups/${backupId}`);
}

// Throttled 6/min, max 100 ids. Answers 200 with `{deleted, succeeded, failed}`: a
// batch can partly fail.
export function deleteBackups(ids) {
  return api.delete("/backups", { data: { ids } });
}
