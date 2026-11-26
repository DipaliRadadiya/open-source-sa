export function usageByDestination(backups) {
  if (!backups || backups.failed || !Array.isArray(backups.lastBackups)) return null;
  const usage = {};
  for (const id of backups.inUse ?? []) usage[id] = { applications: 0, lastBackup: null };
  for (const target of backups.targets ?? []) {
    if (usage[target]) usage[target].applications += 1;
  }
  for (const backup of backups.lastBackups) {
    const entry = usage[backup.storage_destination_id];
    // DD-MM-YYYY dates don't sort as text, so compare ids.
    if (entry && (!entry.lastBackup || backup.id > entry.lastBackup.id)) entry.lastBackup = backup;
  }
  return usage;
}
