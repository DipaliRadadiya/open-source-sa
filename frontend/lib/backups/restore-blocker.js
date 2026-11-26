import { BACKUP_IN_FLIGHT } from "../schemas/backup.js";

export function restoreBlocker(backup, t, restoreInFlight = false, onDatabasePage = false) {
  if (BACKUP_IN_FLIGHT.includes(backup.status)) return t("blocked.inFlight");
  if (backup.status === "failed") return t("blocked.failed");
  if (backup.status !== "verified") return t("blocked.unverified");
  const ofDatabase = Boolean(backup.database_id || backup.database_name);
  if (!onDatabasePage) {
    if (ofDatabase) return backup.archived ? t("blocked.databaseDeleted") : t("blocked.onDatabasePage");
    if (backup.archived) return t("blocked.siteDeleted");
  }
  if (!onDatabasePage && !backup.application_domain) return t("blocked.noApplication");
  if (restoreInFlight) return t("blocked.alreadyRunning");
  return null;
}
