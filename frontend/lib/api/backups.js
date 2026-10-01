import { api } from "@/lib/api/client";

/**
 * Create or update one application's backup settings (one target per
 * application, so both are the same call).
 */
export function saveBackupTarget(applicationId, payload) {
  return api.put(`/applications/${applicationId}/backup-target`, payload);
}

/**
 * Remove an application's backup schedule and settings.
 *
 * The API 422s while backups exist unless `deleteBackups` is set; pausing
 * keeps the archives. Needs `backup` manage, not `app_backup`.
 */
export function deleteBackupTarget(applicationId, { deleteBackups = false } = {}) {
  return api.delete(`/applications/${applicationId}/backup-target`, {
    data: deleteBackups ? { delete_backups: true } : undefined,
  });
}

/** The settings form's choices, for a retry after the server render could not read them. */
export function fetchBackupTargetOptions() {
  return api.get("/backup-targets/options");
}

/**
 * Run a backup now. Throttled to 6/min.
 *
 * Answers 202 with the target, not a backup: the row only exists once a worker
 * picks the job up. 422 when the site has no target or a run is in flight.
 */
export function runBackupNow(applicationId) {
  return api.post(`/applications/${applicationId}/backups`);
}

/**
 * Start a restore. Throttled to 2/min.
 *
 * `confirm` must equal the application's domain exactly and `type` must be a
 * subset of the archive's contents; both are checked client-side first.
 * Answers 202 with the restore row, which exists before the worker starts.
 */
export function startRestore(backupId, payload) {
  return api.post(`/backups/${backupId}/restore`, payload);
}

/** Poll one restore while it runs. Throttled at 120/min. */
export function fetchRestore(restoreId) {
  return api.get(`/restores/${restoreId}`);
}

/**
 * A short-lived presigned link to the archive. Throttled 6/min.
 *
 * Navigate to `url` directly: sending it through Axios would add headers and
 * break the signature. Needs `backup,manage`, not the read tier.
 */
export function fetchBackupDownload(backupId) {
  return api.get(`/backups/${backupId}/download`);
}

/**
 * One backup, fetched on demand. Used for a restore's safety copy, whose type
 * decides what an undo can put back.
 */
export function fetchBackup(backupId) {
  return api.get(`/backups/${backupId}`);
}

/**
 * Re-run a failed backup with the same configuration. Throttled 6/min.
 * Unlike "back up now", this repeats the failed run.
 */
export function retryBackup(backupId) {
  return api.post(`/backups/${backupId}/retry`);
}

/**
 * Close a pending/running row only after its worker has definitely stopped.
 *
 * Not a cancel: the API refuses a job that may still be writing, since freeing
 * the per-site guard early could allow two writers on the same backup key.
 */
export function clearStuckBackup(backupId) {
  return api.post(`/backups/${backupId}/clear`);
}

/**
 * Delete one backup. The server removes the archive before the record so no
 * orphaned object is left in the bucket.
 */
export function deleteBackup(backupId) {
  return api.delete(`/backups/${backupId}`);
}

/**
 * Delete several at once. Throttled 6/min, capped at 100 ids per request
 * (single deletes are throttled at 12/min). Answers 200 with
 * `{deleted, succeeded, failed}` because a batch can partly fail.
 */
export function deleteBackups(ids) {
  return api.delete("/backups", { data: { ids } });
}
