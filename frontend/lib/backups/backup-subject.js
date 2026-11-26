export function backupSubject(backup) {
  if (backup.database_id) {
    return { kind: "database", name: backup.database_name, href: `/databases/${backup.database_id}?tab=backups`, archived: false };
  }
  if (backup.database_name) return { kind: "database", name: backup.database_name, href: null, archived: true };
  if (backup.archived) return { kind: "application", name: backup.site_name, href: null, archived: true };
  return {
    kind: "application",
    name: backup.application_name,
    domain: backup.application_domain,
    href: backup.application_id ? `/applications/${backup.application_id}/backups` : null,
    archived: false,
  };
}
