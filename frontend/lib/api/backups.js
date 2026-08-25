import { api } from "@/lib/api/client";

/**
 * Create or update one application's backup settings.
 *
 * One call for both: the table has a unique on `application_id`, so from the
 * user's side "create" and "update" are the same act — configuring backups for
 * this site. That is also what lets the server-level dialog and the
 * application page share a single form.
 */
export function saveBackupTarget(applicationId, payload) {
  return api.put(`/applications/${applicationId}/backup-target`, payload);
}

/**
 * Run a backup now. Throttled to 6/min on the backend — each call dumps a
 * database and writes a multi-gigabyte archive.
 *
 * Answers 202 with the *target*, not a backup: the row does not exist until a
 * worker picks the job up, so there is nothing to poll straight away.
 * Rejects with a 422 when the site has no target, or when a run is already in
 * flight for it.
 */
export function runBackupNow(applicationId) {
  return api.post(`/applications/${applicationId}/backups`);
}

/**
 * Start a restore. Throttled to 2/min.
 *
 * `confirm` must equal the application's domain exactly, and `type` must be a
 * subset of what the archive holds. Both are checked client-side first — being
 * refused *after* typing your own domain is the worst moment to meet a
 * validation error.
 *
 * Answers 202 with the restore row, which exists before the worker starts:
 * "nothing happened yet" and "it is about to overwrite my site" must not look
 * the same.
 */
export function startRestore(backupId, payload) {
  return api.post(`/backups/${backupId}/restore`, payload);
}

/**
 * Poll one restore while it runs. Throttled at 120/min — deliberately generous,
 * this endpoint was built to be polled.
 */
export function fetchRestore(restoreId) {
  return api.get(`/restores/${restoreId}`);
}

/**
 * A short-lived link to the archive itself. Throttled 6/min.
 *
 * Answers JSON, not a 302, and the presigned signature covers the exact
 * request — so the caller must navigate to `url` directly. Putting it through
 * the configured Axios instance would attach our headers and break the
 * signature, which is why only this metadata call goes through `api`.
 *
 * Needs `backup,manage` — the restore tier, not the read tier. Seeing that
 * backups happened and walking away with every file plus a database dump are
 * different levels of trust.
 */
export function fetchBackupDownload(backupId) {
  return api.get(`/backups/${backupId}/download`);
}

/**
 * One backup, fetched on demand.
 *
 * Used for the safety copy after a restore: the restore only carries
 * `safety_backup_id`, and offering "undo this" means having the row itself —
 * its type decides what the undo can put back.
 */
export function fetchBackup(backupId) {
  return api.get(`/backups/${backupId}`);
}

/**
 * Re-run a failed backup with the same configuration. Throttled 6/min.
 *
 * Distinct from "back up now": that starts a fresh run from the site's current
 * state, which is the honest answer when there is nothing to retry. This
 * repeats the run that failed, which is what someone looking at a failed row
 * actually means.
 */
export function retryBackup(backupId) {
  return api.post(`/backups/${backupId}/retry`);
}

/**
 * Close a pending/running row only after its worker has definitely stopped.
 *
 * This is deliberately not a cancel operation: the API refuses a job that may
 * still be writing the archive, because freeing the per-site guard early could
 * allow two writers to target the same backup key.
 */
export function clearStuckBackup(backupId) {
  return api.post(`/backups/${backupId}/clear`);
}

/**
 * Delete one backup — the archive in object storage first, then the record.
 *
 * That order is the server's and it matters: a row deleted before its archive
 * leaves an object in the customer's bucket that nothing in the panel can find
 * or remove, billed every month until somebody goes looking.
 */
export function deleteBackup(backupId) {
  return api.delete(`/backups/${backupId}`);
}

/**
 * Delete several at once. Throttled 6/min, capped at 100 ids per request.
 *
 * A single endpoint rather than looping `deleteBackup`: that one is throttled
 * at 12/minute and would refuse the thirteenth row of a selection, which is
 * well inside what somebody clearing a month of backups will tick.
 *
 * Answers 200 with `{deleted, succeeded, failed}` rather than 204, because a
 * batch can half-work — the same shape the file manager's bulk operations use.
 */
export function deleteBackups(ids) {
  return api.delete("/backups", { data: { ids } });
}
