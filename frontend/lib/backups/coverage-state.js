/**
 * A site's backup coverage. A disabled or manual target is "paused": it looks
 * configured but backs nothing up.
 *
 * Kept in its own module (no server imports) so tests can use the real rule.
 */
export function classify(target, lastBackup) {
  if (!target) return "unprotected";
  if (!target.enabled || target.frequency === "manual") return "paused";
  // A failing schedule protects nothing; the last run is the only evidence.
  if (lastBackup?.status === "failed") return "failing";
  return "protected";
}
