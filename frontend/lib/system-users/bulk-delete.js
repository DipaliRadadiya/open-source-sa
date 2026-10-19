// The reasons DELETE /system-users gives for an account it kept; anything else is
// the server refusing, worded generically.
export const BULK_REASONS = ["has_applications", "has_processes", "failed"];
// The reasons the user can act on; each has one line of advice in the banner.
export const BULK_FIXES = ["has_applications", "has_processes"];

/** `{succeeded, failed}` from the API → ids that went, and why each other one did not. */
export function bulkDeleteOutcome(data = {}) {
  const succeeded = Array.isArray(data.succeeded) ? data.succeeded : [];
  const failures = {};
  for (const entry of Array.isArray(data.failed) ? data.failed : []) {
    if (entry?.id == null) continue;
    failures[entry.id] = {
      reason: BULK_REASONS.includes(entry.reason) ? entry.reason : "failed",
      reference: entry.reference ?? null,
    };
  }
  return { succeeded, failures };
}
