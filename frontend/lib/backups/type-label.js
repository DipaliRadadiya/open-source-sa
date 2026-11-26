export function backupTypeLabel(backup, t) {
  if (backup?.type === "full" && backup?.has_database === false) return t("types.filesystem");
  return backup?.type_title ?? backup?.type ?? "";
}
