export function classify(target, lastBackup) {
  if (!target) return "unprotected";
  if (!target.enabled || target.frequency === "manual") return "paused";
  if (lastBackup?.status === "failed") return "failing";
  return "protected";
}
