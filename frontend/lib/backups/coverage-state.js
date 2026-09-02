/**
 * Three states, not two. A target that exists but is switched off — or set to
 * manual, which runs on no schedule at all — is the dangerous middle: it looks
 * configured on every screen that only asks "is there a target?", and backs up
 * nothing. It gets its own state so the UI can say so.
 *
 * Its own module rather than a private function in get-backups.js: that file
 * imports the server fetch layer, so a test could not reach this rule and kept
 * a copy of it instead. A copy of the rule that decides whether a site is safe
 * is the last thing that should be allowed to drift from the rule itself.
 */
export function classify(target, lastBackup) {
  if (!target) return "unprotected";
  if (!target.enabled || target.frequency === "manual") return "paused";
  // A schedule that is running and failing protects nothing, and this screen
  // exists to answer "could I get this site back". The last run is the only
  // evidence there is; if it failed, the answer is no.
  if (lastBackup?.status === "failed") return "failing";
  return "protected";
}
