// Per destination: how many applications back up to it and when the newest backup ran.
// `null` when the backup targets could not be read, so the row says nothing instead of "unused".
export function usageByDestination(backups) {
  if (!backups || backups.failed || !Array.isArray(backups.lastBackups)) return null;
  const usage = {};
  for (const id of backups.inUse ?? []) usage[id] = { applications: 0, lastBackup: null };
  for (const target of backups.targets ?? []) {
    if (usage[target]) usage[target].applications += 1;
  }
  for (const backup of backups.lastBackups) {
    const entry = usage[backup.storage_destination_id];
    // Ids grow with time; the API's DD-MM-YYYY dates do not sort as text.
    if (entry && (!entry.lastBackup || backup.id > entry.lastBackup.id)) entry.lastBackup = backup;
  }
  return usage;
}
