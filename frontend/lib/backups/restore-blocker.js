import { BACKUP_IN_FLIGHT } from "../schemas/backup.js";

// Mirrors `RestoreBackupRequest::withValidator()` in order; null when restorable.
// `onDatabasePage`: the list on a database's own Backups tab, which restores there.
export function restoreBlocker(backup, t, restoreInFlight = false, onDatabasePage = false) {
  // A backup still being written has simply not finished; don't call it "unverified".
  if (BACKUP_IN_FLIGHT.includes(backup.status)) return t("blocked.inFlight");
  if (backup.status === "failed") return t("blocked.failed");
  if (backup.status !== "verified") return t("blocked.unverified");
  const ofDatabase = Boolean(backup.database_id || backup.database_name);
  if (!onDatabasePage) {
    // The shared route refuses these: a database is restored from its own page,
    // a deleted site's backup can only be downloaded.
    if (ofDatabase) return backup.archived ? t("blocked.databaseDeleted") : t("blocked.onDatabasePage");
    if (backup.archived) return t("blocked.siteDeleted");
  }
  if (!onDatabasePage && !backup.application_domain) return t("blocked.noApplication");
  if (restoreInFlight) return t("blocked.alreadyRunning");
  return null;
}
