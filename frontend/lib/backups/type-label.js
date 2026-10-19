// What a backup holds, for a list row. A site with no database still gets `full`
// backups with no dump in them (`has_database: false`), so "Files and database" would be wrong.
// `t` is the `backups.history` translator.
export function backupTypeLabel(backup, t) {
  if (backup?.type === "full" && backup?.has_database === false) return t("types.filesystem");
  return backup?.type_title ?? backup?.type ?? "";
}
